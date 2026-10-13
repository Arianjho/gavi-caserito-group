<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Platform\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Cotizaciones: alta con precios calculados, cambio de estado y conversión
 * en pedido (que recién ahí reserva stock).
 */
final class QuotationService
{
    /**
     * Crea el servicio.
     *
     * @param LinePricer $pricer Calculador de líneas y totales.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function __construct(
        private readonly LinePricer $pricer,
        private readonly OrderService $orders,
    ) {}

    /**
     * Registra una cotización.
     *
     * @param string $tenantId Cuenta.
     * @param string $companyId Empresa que cotiza.
     * @param string $partyId Cliente.
     * @param list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct?: numeric-string|null}> $lines Líneas.
     * @param string|null $validUntil Vigencia (fecha).
     * @param string|null $notes Condiciones.
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $companyId, string $partyId, array $lines, ?string $validUntil, ?string $notes): array
    {
        return DB::transaction(function () use ($tenantId, $companyId, $partyId, $lines, $validUntil, $notes): array {
            Company::query()->findOrFail($companyId);
            [$computed, $total] = $this->pricer->price($lines);
            $id = (string) Str::uuid();
            $userId = request()->user()?->getAuthIdentifier();

            DB::table('sales.quotations')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'company_id' => $companyId,
                'number' => (int) DB::table('sales.quotations')->where('company_id', $companyId)->max('number') + 1,
                'party_id' => $partyId, 'seller_id' => $userId, 'valid_until' => $validUntil,
                'total' => $total, 'notes' => $notes, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            foreach ($computed as $line) {
                DB::table('sales.quotation_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'quotation_id' => $id,
                    'line_no' => $line['line_no'], 'variant_id' => $line['variant_id'],
                    'description' => $line['description'], 'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'], 'discount_pct' => $line['discount_pct'],
                    'total' => $line['total'],
                ]);
            }

            return $this->find($id);
        });
    }

    /**
     * Marca una cotización abierta como rechazada.
     *
     * @param string $id Cotización.
     * @return array<string, mixed>
     */
    public function reject(string $id): array
    {
        return DB::transaction(function () use ($id): array {
            $this->lockOpen($id);
            DB::table('sales.quotations')->where('id', $id)->update(['status' => 'rejected', 'updated_at' => now()]);

            return $this->find($id);
        });
    }

    /**
     * Convierte la cotización en pedido reservando el stock.
     *
     * @param string $id Cotización.
     * @param string $warehouseId Almacén que reserva y entrega.
     * @param array{delivery_type: string, source: string, requested_date: string|null, notes: string|null} $header Datos del pedido.
     * @return array<string, mixed> Pedido generado.
     */
    public function convert(string $id, string $warehouseId, array $header): array
    {
        return DB::transaction(function () use ($id, $warehouseId, $header): array {
            $quotation = $this->lockOpen($id);
            $lines = DB::table('sales.quotation_lines')->where('quotation_id', $id)->orderBy('line_no')->get();
            /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string}> $orderLines */
            $orderLines = $lines->map(fn (stdClass $line): array => [
                'variant_id' => $line->variant_id, 'qty' => (string) $line->qty,
                'unit_price' => (string) $line->unit_price, 'discount_pct' => (string) $line->discount_pct,
            ])->all();

            $order = $this->orders->create($quotation->tenant_id, $warehouseId, $quotation->party_id, $orderLines, $header, null);
            DB::table('sales.quotations')->where('id', $id)->update([
                'status' => 'converted', 'order_id' => $order['id'], 'updated_at' => now(),
            ]);

            return $order;
        });
    }

    /**
     * Devuelve una cotización con sus líneas.
     *
     * @param string $id Cotización.
     * @return array<string, mixed>
     */
    public function find(string $id): array
    {
        $quotation = DB::table('sales.quotations as q')->join('cat.parties as p', 'p.id', '=', 'q.party_id')
            ->where('q.id', $id)->first(['q.*', 'p.name as party_name']);
        if ($quotation === null) {
            throw new SaleException('not_found', 'Cotización no encontrada.', 404);
        }

        return (array) $quotation + [
            'lines' => DB::table('sales.quotation_lines')->where('quotation_id', $id)->orderBy('line_no')->get()->all(),
        ];
    }

    /**
     * Bloquea la cotización exigiendo que siga abierta y vigente.
     *
     * @param string $id Cotización.
     */
    private function lockOpen(string $id): stdClass
    {
        $quotation = DB::table('sales.quotations')->where('id', $id)->lockForUpdate()->first();
        if ($quotation === null) {
            throw new SaleException('not_found', 'Cotización no encontrada.', 404);
        }
        if ($quotation->status !== 'open') {
            throw new SaleException('invalid_transition', 'La cotización ya no está abierta.', 409);
        }
        if ($quotation->valid_until !== null && $quotation->valid_until < now()->toDateString()) {
            throw new SaleException('quotation_expired', 'La cotización venció.', 409);
        }

        return $quotation;
    }
}
