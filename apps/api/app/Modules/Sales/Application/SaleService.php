<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Cash\Application\CashService;
use App\Modules\Catalog\Application\PriceResolver;
use App\Modules\Fiscal\Application\EfactCompatibleCalculator;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Platform\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra y anula ventas: calcula importes, numera, descuenta stock y
 * registra los cobros dentro de una única transacción.
 */
final class SaleService
{
    /**
     * Crea el servicio.
     *
     * @param EfactCompatibleCalculator $calculator Calculador de importes.
     * @param StockService $stock Servicio de stock.
     * @param CashService $cash Servicio de caja.
     * @param PriceResolver $prices Resolutor de precios por lista.
     */
    public function __construct(
        private readonly EfactCompatibleCalculator $calculator,
        private readonly StockService $stock,
        private readonly CashService $cash,
        private readonly PriceResolver $prices,
    ) {}

    /**
     * Registra una venta confirmada.
     *
     * @param SaleData $data Datos de la venta.
     * @return array<string, mixed> Documento registrado con sus líneas y cobros.
     *
     * @throws SaleException Si falta precio, el cobro no cuadra o no hay stock.
     */
    public function create(SaleData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $warehouse = Warehouse::query()->findOrFail($data->warehouseId);
            $computed = $this->computeLines($this->withListPrices($data->partyId, $data->lines));
            $totals = $this->calculator->document($computed);
            $credit = $data->creditDueDate !== null;
            if ($credit && $data->partyId === null) {
                throw new SaleException('party_required', 'Una venta a crédito requiere un cliente.', 422);
            }

            $payments = $data->payments;
            if (bccomp($data->advance, '0', 2) > 0) {
                $payments[] = ['method' => 'advance', 'amount' => $data->advance, 'reference' => 'Anticipo de pedido'];
            }
            $paid = $this->assertPayments($payments, $totals['total'], $credit);
            $this->cash->assertSessionIfRequired(
                $warehouse->branch_id,
                request()->user()?->getAuthIdentifier(),
                in_array('cash', array_column($payments, 'method'), true),
            );

            $series = $this->takeNumber($data->tenantId, $warehouse);
            $documentId = (string) Str::uuid();

            DB::table('sales.sales_documents')->insert([
                'id' => $documentId, 'tenant_id' => $data->tenantId, 'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'series_id' => $series['id'], 'doc_type' => 'ticket', 'series' => $series['series'], 'number' => $series['number'],
                'taxable_total' => $totals['taxable'], 'exempt_total' => $totals['exempt'],
                'unaffected_total' => $totals['unaffected'], 'igv_total' => $totals['igv'],
                'total' => $totals['total'], 'paid_total' => $paid, 'notes' => $data->notes,
                'party_id' => $data->partyId, 'order_id' => $data->orderId,
                'payment_condition' => $credit ? 'credit' : 'cash', 'due_date' => $data->creditDueDate,
                'created_by' => request()->user()?->getAuthIdentifier(),
                'updated_by' => request()->user()?->getAuthIdentifier(),
            ]);

            $this->persistLines($data->tenantId, $documentId, $warehouse, $computed);
            $this->persistPayments($data->tenantId, $documentId, $payments);

            return $this->find($documentId);
        });
    }

    /**
     * Anula una venta y devuelve el stock.
     *
     * @param string $documentId Documento a anular.
     * @param string $reason Motivo de la anulación.
     * @return array<string, mixed> Documento anulado.
     *
     * @throws SaleException Si ya estaba anulado.
     */
    public function void(string $documentId, string $reason): array
    {
        return DB::transaction(function () use ($documentId, $reason): array {
            $doc = DB::table('sales.sales_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($doc === null) {
                throw new SaleException('not_found', 'Documento no encontrado.', 404);
            }
            if ($doc->status === 'voided') {
                throw new SaleException('already_voided', 'El documento ya está anulado.', 409);
            }
            $this->assertNoFiscalDocument($documentId);

            $lines = DB::table('sales.sales_document_lines as l')
                ->join('cat.item_variants as v', 'v.id', '=', 'l.variant_id')
                ->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('l.document_id', $documentId)
                ->get(['l.variant_id', 'l.qty', 'l.unit_cost', 'i.tracks_stock']);

            foreach ($lines as $line) {
                if ($line->tracks_stock) {
                    /** @var numeric-string $qty */
                    $qty = (string) $line->qty;
                    /** @var numeric-string|null $cost */
                    $cost = $line->unit_cost === null ? null : (string) $line->unit_cost;
                    $this->stock->post($doc->warehouse_id, $line->variant_id, 'sale_return', $qty, $cost, [
                        'source_type' => 'sale_void', 'source_id' => $documentId, 'reason' => $reason,
                    ]);
                }
            }

            DB::table('sales.sales_documents')->where('id', $documentId)->update([
                'status' => 'voided', 'voided_at' => now(), 'void_reason' => $reason,
                'voided_by' => request()->user()?->getAuthIdentifier(),
                'updated_at' => now(), 'version' => $doc->version + 1,
            ]);

            return $this->find($documentId);
        });
    }

    /**
     * Impide anular con devolución simple una venta que ya tiene comprobante electrónico vigente.
     *
     * @param string $documentId Venta.
     *
     * @throws SaleException Si hay un comprobante pendiente o aceptado.
     */
    private function assertNoFiscalDocument(string $documentId): void
    {
        $issued = DB::table('fiscal.documents')->where('sales_document_id', $documentId)
            ->whereIn('status', ['pending', 'accepted'])->exists();
        if ($issued) {
            throw new SaleException(
                'fiscal_document_issued',
                'La venta tiene un comprobante electrónico; anúlalo con nota de crédito o baja.',
                409,
            );
        }
    }

    /**
     * Devuelve un documento con sus líneas y cobros.
     *
     * @param string $documentId Identificador del documento.
     * @return array<string, mixed>
     */
    public function find(string $documentId): array
    {
        $doc = DB::table('sales.sales_documents')->where('id', $documentId)->first();
        if ($doc === null) {
            throw new SaleException('not_found', 'Documento no encontrado.', 404);
        }

        return (array) $doc + [
            'lines' => DB::table('sales.sales_document_lines')->where('document_id', $documentId)
                ->orderBy('line_no')->get()->all(),
            'payments' => DB::table('sales.document_payments')->where('document_id', $documentId)
                ->orderBy('paid_at')->get()->all(),
        ];
    }

    /**
     * Completa el precio de las líneas sin precio explícito con el de la
     * lista del cliente (escala por cantidad), si la tiene.
     *
     * @param string|null $partyId Cliente de la venta.
     * @param list<array{variant_id: string, qty: numeric-string, unit_price?: numeric-string|null, discount_pct?: numeric-string|null}> $lines Líneas de entrada.
     * @return list<array{variant_id: string, qty: numeric-string, unit_price?: numeric-string|null, discount_pct?: numeric-string|null}>
     */
    private function withListPrices(?string $partyId, array $lines): array
    {
        if ($partyId === null) {
            return $lines;
        }
        $resolved = $this->prices->resolve($partyId, array_map(
            fn (array $line): array => ['variant_id' => $line['variant_id'], 'qty' => $line['qty']],
            $lines,
        ));
        foreach ($lines as $index => $line) {
            $match = $resolved[$index];
            if (($line['unit_price'] ?? null) === null && $match['source'] === 'list' && $match['unit_price'] !== null) {
                /** @var numeric-string $price */
                $price = $match['unit_price'];
                $lines[$index]['unit_price'] = $price;
            }
        }

        return $lines;
    }

    /**
     * Calcula las líneas con el calculador compatible con eFact.
     *
     * @param list<array{variant_id: string, qty: numeric-string, unit_price?: numeric-string|null, discount_pct?: numeric-string|null}> $lines Líneas de entrada.
     * @return list<array{line_no: int, variant_id: string, description: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string, affectation: string, tracks_stock: bool, unit_value: numeric-string, discount: numeric-string, sale_value: numeric-string, igv: numeric-string, total: numeric-string}>
     */
    private function computeLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $index => $line) {
            $variant = DB::table('cat.item_variants as v')
                ->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('v.id', $line['variant_id'])
                ->first(['v.id', 'v.name as variant_name', 'v.sale_price', 'i.name', 'i.tracks_stock', 'i.default_tax_affectation']);

            if ($variant === null) {
                throw new SaleException('variant_not_found', 'Un producto de la venta ya no existe.', 422);
            }

            $price = $line['unit_price'] ?? ($variant->sale_price === null ? null : (string) $variant->sale_price);
            if ($price === null) {
                throw new SaleException('price_missing', "El producto \"{$variant->name}\" no tiene precio de venta.", 422);
            }

            $affectation = (string) ($variant->default_tax_affectation ?? '10');
            /** @var numeric-string $price */
            $calc = $this->calculator->line($price, $line['qty'], $line['discount_pct'] ?? '0', $affectation);

            $result[] = $calc + [
                'line_no' => $index + 1,
                'variant_id' => $line['variant_id'],
                'description' => trim($variant->name.' '.($variant->variant_name ?? '')),
                'qty' => $line['qty'],
                'unit_price' => $price,
                'discount_pct' => $line['discount_pct'] ?? '0',
                'affectation' => $affectation,
                'tracks_stock' => (bool) $variant->tracks_stock,
            ];
        }

        return $result;
    }

    /**
     * Verifica que los cobros cubran exactamente el total.
     *
     * @param list<array{method: string, amount: numeric-string, reference?: string|null}> $payments Cobros.
     * @param numeric-string $total Total del documento.
     * @param bool $credit Si la venta es a crédito (cobro parcial permitido).
     * @return numeric-string Total cobrado.
     */
    private function assertPayments(array $payments, string $total, bool $credit = false): string
    {
        $paid = '0.00';
        foreach ($payments as $payment) {
            $paid = bcadd($paid, $payment['amount'], 2);
        }
        $mismatch = $credit ? bccomp($paid, $total, 2) > 0 : bccomp($paid, $total, 2) !== 0;
        if ($mismatch) {
            $rule = $credit ? 'no pueden superar' : 'deben sumar';
            throw new SaleException('payment_mismatch', "Los cobros ({$paid}) {$rule} el total ({$total}).", 422);
        }

        return $paid;
    }

    /**
     * Toma el siguiente correlativo de la serie de tickets de la sucursal, creándola si falta.
     *
     * @param string $tenantId Cuenta.
     * @param Warehouse $warehouse Almacén (define empresa y sucursal).
     * @return array{id: string, series: string, number: int}
     */
    private function takeNumber(string $tenantId, Warehouse $warehouse): array
    {
        $query = DB::table('sales.document_series')
            ->where('company_id', $warehouse->company_id)->where('branch_id', $warehouse->branch_id)
            ->where('doc_type', 'ticket')->where('status', 'active');

        $series = (clone $query)->lockForUpdate()->first();
        if ($series === null) {
            DB::table('sales.document_series')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id, 'doc_type' => 'ticket', 'series' => 'N001',
            ]);
            $series = $query->lockForUpdate()->first();
        }
        if ($series === null) {
            throw new SaleException('series_missing', 'No se pudo obtener la serie de numeración.', 500);
        }

        DB::table('sales.document_series')->where('id', $series->id)->increment('next_number');

        return ['id' => $series->id, 'series' => $series->series, 'number' => (int) $series->next_number];
    }

    /**
     * Inserta las líneas y descuenta el stock de las que lo controlan.
     *
     * @param string $tenantId Cuenta.
     * @param string $documentId Documento.
     * @param Warehouse $warehouse Almacén.
     * @param list<array{line_no: int, variant_id: string, description: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string, affectation: string, tracks_stock: bool, unit_value: numeric-string, discount: numeric-string, sale_value: numeric-string, igv: numeric-string, total: numeric-string}> $computed Líneas calculadas.
     */
    private function persistLines(string $tenantId, string $documentId, Warehouse $warehouse, array $computed): void
    {
        foreach ($computed as $line) {
            $cost = null;
            if ($line['tracks_stock']) {
                $moved = $this->stock->post($warehouse->id, $line['variant_id'], 'sale', $line['qty'], null, [
                    'source_type' => 'sale', 'source_id' => $documentId,
                ]);
                $cost = $moved['avg_cost'];
            }

            DB::table('sales.sales_document_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'document_id' => $documentId,
                'line_no' => $line['line_no'], 'variant_id' => $line['variant_id'], 'description' => $line['description'],
                'qty' => $line['qty'], 'unit_price' => $line['unit_price'], 'discount_pct' => $line['discount_pct'],
                'affectation' => $line['affectation'], 'unit_value' => $line['unit_value'], 'sale_value' => $line['sale_value'],
                'igv' => $line['igv'], 'total' => $line['total'], 'unit_cost' => $cost,
            ]);
        }
    }

    /**
     * Inserta los cobros del documento.
     *
     * @param string $tenantId Cuenta.
     * @param string $documentId Documento.
     * @param list<array{method: string, amount: numeric-string, reference?: string|null}> $payments Cobros.
     */
    private function persistPayments(string $tenantId, string $documentId, array $payments): void
    {
        $sessionId = $this->cash->currentSessionId((string) request()->user()?->getAuthIdentifier());
        foreach ($payments as $payment) {
            DB::table('sales.document_payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'document_id' => $documentId,
                'method' => $payment['method'], 'amount' => $payment['amount'], 'reference' => $payment['reference'] ?? null,
                'cash_session_id' => $payment['method'] === 'advance' ? null : $sessionId,
                'created_by' => request()->user()?->getAuthIdentifier(),
            ]);
        }
    }
}
