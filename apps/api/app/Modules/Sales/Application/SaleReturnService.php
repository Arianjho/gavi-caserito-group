<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Cash\Application\CashService;
use App\Modules\Inventory\Application\StockService;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Devoluciones de venta: reingresa el stock, devuelve el dinero y deja el
 * documento original intacto. Una venta con comprobante electrónico vigente
 * se corrige con nota de crédito, no con esta devolución.
 */
final class SaleReturnService
{
    /**
     * Crea el servicio.
     *
     * @param StockService $stock Servicio de stock.
     * @param CashService $cash Servicio de caja.
     */
    public function __construct(
        private readonly StockService $stock,
        private readonly CashService $cash,
    ) {}

    /**
     * Registra una devolución.
     *
     * @param string $saleId Venta.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Cantidades devueltas por línea.
     * @param string $method Medio con el que se devuelve el dinero.
     * @param string $reason Motivo.
     * @param bool $viaCreditNote `true` si la corrige una nota de crédito electrónica (permite el comprobante vigente).
     * @return array<string, mixed>
     */
    public function create(string $saleId, array $lines, string $method, string $reason, bool $viaCreditNote = false): array
    {
        return DB::transaction(function () use ($saleId, $lines, $method, $reason, $viaCreditNote): array {
            $sale = $this->lockSale($saleId, $viaCreditNote);
            $userId = (string) request()->user()?->getAuthIdentifier();
            $items = $this->collect($saleId, $lines);

            $total = '0';
            $net = '0';
            $cost = '0';
            foreach ($items as $item) {
                $total = bcadd($total, $item['total'], 2);
                $net = bcadd($net, $item['net'], 2);
                $cost = bcadd($cost, $item['cost'], 2);
            }

            $sessionId = $method === 'cash' ? $this->cash->currentSessionId($userId) : null;
            if ($method === 'cash') {
                $this->cash->assertSessionIfRequired((string) $sale->branch_id, $userId, true);
            }

            $id = (string) Str::uuid();
            DB::table('sales.sale_returns')->insert([
                'id' => $id, 'tenant_id' => $sale->tenant_id, 'company_id' => $sale->company_id,
                'number' => (int) DB::table('sales.sale_returns')->where('company_id', $sale->company_id)->max('number') + 1,
                'sale_id' => $saleId, 'reason' => $reason, 'refund_method' => $method,
                'total' => $total, 'net_value' => $net, 'cost' => $cost, 'cash_session_id' => $sessionId,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $this->persistLines($sale, $id, $items, $reason);

            if ($sessionId !== null && bccomp($total, '0', 2) > 0) {
                $this->cash->addMovement(
                    (string) $sale->tenant_id, $userId, $sessionId, 'out', $total,
                    "Devolución de {$sale->series}-{$sale->number}",
                );
            }

            return $this->find($id);
        });
    }

    /**
     * Devoluciones de una venta.
     *
     * @param string $saleId Venta.
     * @return array<int, array<string, mixed>>
     */
    public function forSale(string $saleId): array
    {
        $returns = DB::table('sales.sale_returns')->where('sale_id', $saleId)->orderBy('returned_at')->get();
        $lines = DB::table('sales.sale_return_lines')->whereIn('return_id', $returns->pluck('id'))->get()->groupBy('return_id');

        return $returns->map(fn (stdClass $r): array => (array) $r + ['lines' => $lines->get($r->id)?->all() ?? []])->all();
    }

    /**
     * Devuelve una devolución con sus líneas.
     *
     * @param string $id Devolución.
     * @return array<string, mixed>
     */
    public function find(string $id): array
    {
        $return = DB::table('sales.sale_returns')->where('id', $id)->first();
        if ($return === null) {
            throw new SaleException('not_found', 'Devolución no encontrada.', 404);
        }

        return (array) $return + [
            'lines' => DB::table('sales.sale_return_lines')->where('return_id', $id)->get()->all(),
        ];
    }

    /**
     * Bloquea la venta y verifica que admita devoluciones.
     *
     * @param string $saleId Venta.
     * @param bool $viaCreditNote Si la devolución la respalda una nota de crédito.
     *
     * @throws SaleException Si está anulada, tiene comprobante vigente o saldo pendiente.
     */
    private function lockSale(string $saleId, bool $viaCreditNote = false): stdClass
    {
        $sale = DB::table('sales.sales_documents')->where('id', $saleId)->lockForUpdate()->first();
        if ($sale === null) {
            throw new SaleException('not_found', 'Venta no encontrada.', 404);
        }
        if ($sale->status !== 'confirmed') {
            throw new SaleException('document_voided', 'La venta está anulada.', 409);
        }
        $issued = ! $viaCreditNote && DB::table('fiscal.documents')->where('sales_document_id', $saleId)
            ->whereIn('status', ['pending', 'accepted'])->exists();
        if ($issued) {
            throw new SaleException('fiscal_document_issued', 'La venta tiene comprobante electrónico; corrígela con nota de crédito.', 409);
        }
        if (bccomp(Decimal::of($sale->total), Decimal::of($sale->paid_total), 2) > 0) {
            throw new SaleException('balance_pending', 'Cobra el saldo pendiente antes de registrar una devolución.', 422);
        }

        return $sale;
    }

    /**
     * Valida cantidades y calcula importes por línea.
     *
     * @param string $saleId Venta.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Líneas pedidas.
     * @return list<array{line: stdClass, qty: numeric-string, total: numeric-string, net: numeric-string, cost: numeric-string}>
     */
    private function collect(string $saleId, array $lines): array
    {
        $items = [];
        foreach ($lines as $input) {
            $line = DB::table('sales.sales_document_lines')->where('id', $input['line_id'])
                ->where('document_id', $saleId)->lockForUpdate()->first();
            if ($line === null) {
                throw new SaleException('line_not_found', 'Una línea no pertenece a la venta.', 422);
            }
            $returned = Decimal::of(DB::table('sales.sale_return_lines')->where('sale_line_id', $line->id)->sum('qty'));
            $left = bcsub(Decimal::of($line->qty), $returned, 3);
            if (bccomp($input['qty'], $left, 3) > 0) {
                throw new SaleException('qty_exceeds_sold', "La cantidad supera lo que se puede devolver ({$left}).", 422);
            }
            $items[] = ['line' => $line, 'qty' => $input['qty']] + $this->amounts($line, $input['qty'], $left);
        }

        return $items;
    }

    /**
     * Importes proporcionales de una línea; si devuelve todo lo restante se
     * toma el saldo exacto para no acumular centavos de redondeo.
     *
     * @param stdClass $line Línea vendida.
     * @param numeric-string $qty Cantidad devuelta.
     * @param numeric-string $left Cantidad aún devolvible.
     * @return array{total: numeric-string, net: numeric-string, cost: numeric-string}
     */
    private function amounts(stdClass $line, string $qty, string $left): array
    {
        $ratio = bcdiv($qty, Decimal::of($line->qty), 10);
        $returnedTotal = Decimal::of(DB::table('sales.sale_return_lines')->where('sale_line_id', $line->id)->sum('total'));
        $total = bccomp($qty, $left, 3) === 0
            ? bcsub(Decimal::of($line->total), $returnedTotal, 2)
            : $this->roundHalfUp(bcmul(Decimal::of($line->total), $ratio, 6));

        return [
            'total' => $total,
            'net' => $this->roundHalfUp(bcmul(Decimal::of($line->sale_value), $ratio, 6)),
            'cost' => $this->roundHalfUp(bcmul(Decimal::of($line->unit_cost ?? 0), $qty, 6)),
        ];
    }

    /**
     * Redondea a 2 decimales (mitad hacia arriba) un valor positivo.
     *
     * @param numeric-string $value Valor con más decimales.
     * @return numeric-string
     */
    private function roundHalfUp(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    /**
     * Inserta las líneas devueltas y reingresa el stock.
     *
     * @param stdClass $sale Venta.
     * @param string $returnId Devolución.
     * @param list<array{line: stdClass, qty: numeric-string, total: numeric-string, net: numeric-string, cost: numeric-string}> $items Líneas calculadas.
     * @param string $reason Motivo.
     */
    private function persistLines(stdClass $sale, string $returnId, array $items, string $reason): void
    {
        foreach ($items as $item) {
            $line = $item['line'];
            DB::table('sales.sale_return_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $sale->tenant_id, 'return_id' => $returnId,
                'sale_line_id' => $line->id, 'variant_id' => $line->variant_id,
                'qty' => $item['qty'], 'total' => $item['total'],
            ]);
            $tracks = (bool) DB::table('cat.item_variants as v')->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('v.id', $line->variant_id)->value('i.tracks_stock');
            if ($tracks) {
                /** @var numeric-string|null $cost */
                $cost = $line->unit_cost === null ? null : (string) $line->unit_cost;
                $this->stock->post($sale->warehouse_id, $line->variant_id, 'sale_return', $item['qty'], $cost, [
                    'source_type' => 'sale_return', 'source_id' => $returnId, 'reason' => $reason,
                ]);
            }
        }
    }
}
