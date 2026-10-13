<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Cash\Application\CashService;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Platform\Models\Warehouse;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Ciclo de vida de los pedidos: alta con reserva y anticipo, avance de
 * estado, entrega (genera la venta) y cancelación (libera la reserva).
 */
final class OrderService
{
    /**
     * Estados que admiten avanzar manualmente y su siguiente estado válido.
     *
     * @var array<string, string>
     */
    private const NEXT = ['confirmed' => 'in_dispatch', 'in_dispatch' => 'ready'];

    /**
     * Crea el servicio.
     *
     * @param LinePricer $pricer Calculador de líneas y totales.
     * @param StockService $stock Servicio de stock.
     * @param SaleService $sales Servicio de ventas.
     * @param CashService $cash Servicio de caja.
     */
    public function __construct(
        private readonly LinePricer $pricer,
        private readonly StockService $stock,
        private readonly SaleService $sales,
        private readonly CashService $cash,
    ) {}

    /**
     * Registra un pedido, reserva el stock y guarda el anticipo si lo hay.
     *
     * @param string $tenantId Cuenta.
     * @param string $warehouseId Almacén que reserva y entrega.
     * @param string $partyId Cliente.
     * @param list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct?: numeric-string|null}> $lines Líneas.
     * @param array{delivery_type: string, source: string, requested_date: string|null, notes: string|null} $header Datos de cabecera.
     * @param array{method: string, amount: numeric-string, reference?: string|null}|null $prepaid Anticipo inicial.
     * @return array<string, mixed>
     */
    public function create(string $tenantId, string $warehouseId, string $partyId, array $lines, array $header, ?array $prepaid): array
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $partyId, $lines, $header, $prepaid): array {
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            $orderId = (string) Str::uuid();
            [$computed, $total] = $this->computeLines($lines);

            if ($prepaid !== null && bccomp($prepaid['amount'], $total, 2) > 0) {
                throw new SaleException('prepaid_exceeds_total', 'El anticipo supera el total del pedido.', 422);
            }

            DB::table('sales.orders')->insert([
                'id' => $orderId, 'tenant_id' => $tenantId, 'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouse->id,
                'number' => $this->nextNumber($warehouse->company_id), 'party_id' => $partyId,
                'seller_id' => request()->user()?->getAuthIdentifier(), 'source' => $header['source'],
                'delivery_type' => $header['delivery_type'], 'requested_date' => $header['requested_date'],
                'total' => $total, 'prepaid_total' => $prepaid['amount'] ?? '0.00', 'notes' => $header['notes'],
                'public_token' => Str::random(32),
                'created_by' => request()->user()?->getAuthIdentifier(),
                'updated_by' => request()->user()?->getAuthIdentifier(),
            ]);

            $this->persistLines($tenantId, $orderId, $warehouseId, $computed);
            $this->persistPrepayment($tenantId, $orderId, $prepaid);

            return $this->find($orderId);
        });
    }

    /**
     * Avanza el pedido al siguiente estado de despacho.
     *
     * @param string $orderId Pedido.
     * @return array<string, mixed>
     */
    public function advance(string $orderId): array
    {
        return DB::transaction(function () use ($orderId): array {
            $order = $this->lock($orderId);
            $next = self::NEXT[$order->status] ?? null;
            if ($next === null) {
                throw new SaleException('invalid_transition', 'El pedido no puede avanzar desde su estado actual.', 409);
            }
            DB::table('sales.orders')->where('id', $orderId)->update([
                'status' => $next, 'updated_at' => now(), 'version' => $order->version + 1,
            ]);

            return $this->find($orderId);
        });
    }

    /**
     * Entrega total o parcial: genera la venta, consume la reserva y aplica el anticipo.
     *
     * @param string $orderId Pedido.
     * @param list<array{line_id: string, qty: numeric-string}> $deliveries Cantidades a entregar por línea.
     * @param list<array{method: string, amount: numeric-string, reference?: string|null}> $payments Cobros al entregar.
     * @param string|null $creditDueDate Vencimiento si el saldo queda a crédito.
     * @return array<string, mixed> Pedido actualizado con la venta generada.
     */
    public function deliver(string $orderId, array $deliveries, array $payments, ?string $creditDueDate): array
    {
        return DB::transaction(function () use ($orderId, $deliveries, $payments, $creditDueDate): array {
            $order = $this->lock($orderId);
            if (in_array($order->status, ['cancelled', 'delivered'], true)) {
                throw new SaleException('invalid_transition', 'El pedido ya está cerrado.', 409);
            }

            [$saleLines, $applied] = $this->collectDeliveries($orderId, $deliveries);
            $this->consumeReservations($order->warehouse_id, $applied);

            /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string}> $saleLines */
            $advance = $this->advanceFor($order, $saleLines);
            $sale = $this->sales->create(new SaleData(
                tenantId: $order->tenant_id, warehouseId: $order->warehouse_id, lines: $saleLines,
                payments: $payments, notes: "Pedido #{$order->number}", partyId: $order->party_id,
                creditDueDate: $creditDueDate, orderId: $orderId, advance: $advance,
            ));

            DB::table('sales.orders')->where('id', $orderId)->update([
                'prepaid_applied' => bcadd(Decimal::of($order->prepaid_applied), $advance, 2),
                'updated_at' => now(), 'version' => $order->version + 1,
            ]);
            $this->closeIfComplete($orderId);

            return $this->find($orderId) + ['sale' => $sale];
        });
    }

    /**
     * Cancela el pedido y libera sus reservas.
     *
     * @param string $orderId Pedido.
     * @param string $reason Motivo.
     * @return array<string, mixed>
     */
    public function cancel(string $orderId, string $reason): array
    {
        return DB::transaction(function () use ($orderId, $reason): array {
            $order = $this->lock($orderId);
            if (in_array($order->status, ['cancelled', 'delivered'], true)) {
                throw new SaleException('invalid_transition', 'El pedido ya está cerrado.', 409);
            }

            $lines = DB::table('sales.order_lines')->where('order_id', $orderId)->lockForUpdate()->get();
            foreach ($lines as $line) {
                if (bccomp(Decimal::of($line->qty_reserved), '0', 3) > 0) {
                    /** @var numeric-string $reserved */
                    $reserved = Decimal::of($line->qty_reserved);
                    $this->stock->release($order->warehouse_id, $line->variant_id, $reserved);
                }
            }
            DB::table('sales.order_lines')->where('order_id', $orderId)->update(['qty_reserved' => 0]);
            DB::table('sales.orders')->where('id', $orderId)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason,
                'updated_at' => now(), 'version' => $order->version + 1,
            ]);

            return $this->find($orderId);
        });
    }

    /**
     * Devuelve un pedido con líneas, anticipos y ventas generadas.
     *
     * @param string $orderId Pedido.
     * @return array<string, mixed>
     */
    public function find(string $orderId): array
    {
        $order = DB::table('sales.orders')->where('id', $orderId)->first();
        if ($order === null) {
            throw new SaleException('not_found', 'Pedido no encontrado.', 404);
        }

        return (array) $order + [
            'lines' => DB::table('sales.order_lines')->where('order_id', $orderId)->orderBy('line_no')->get()->all(),
            'prepayments' => DB::table('sales.order_payments')->where('order_id', $orderId)->orderBy('paid_at')->get()->all(),
            'sales' => DB::table('sales.sales_documents')->where('order_id', $orderId)
                ->orderBy('issued_at')->get(['id', 'series', 'number', 'status', 'total'])->all(),
        ];
    }

    /**
     * Inserta las líneas del pedido y reserva el stock de las que lo controlan.
     *
     * @param string $tenantId Cuenta.
     * @param string $orderId Pedido.
     * @param string $warehouseId Almacén que reserva.
     * @param list<array<string, mixed>> $computed Líneas calculadas.
     */
    private function persistLines(string $tenantId, string $orderId, string $warehouseId, array $computed): void
    {
        foreach ($computed as $line) {
            $tracks = (bool) DB::table('cat.item_variants as v')->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('v.id', $line['variant_id'])->value('i.tracks_stock');
            if ($tracks) {
                $this->stock->reserve($warehouseId, $line['variant_id'], $line['qty']);
            }
            DB::table('sales.order_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'order_id' => $orderId,
                'line_no' => $line['line_no'], 'variant_id' => $line['variant_id'], 'description' => $line['description'],
                'qty_ordered' => $line['qty'], 'qty_reserved' => $tracks ? $line['qty'] : '0',
                'unit_price' => $line['unit_price'], 'discount_pct' => $line['discount_pct'],
                'affectation' => $line['affectation'], 'total' => $line['total'],
            ]);
        }
    }

    /**
     * Registra el anticipo inicial del pedido, si existe.
     *
     * @param string $tenantId Cuenta.
     * @param string $orderId Pedido.
     * @param array{method: string, amount: numeric-string, reference?: string|null}|null $prepaid Anticipo.
     */
    private function persistPrepayment(string $tenantId, string $orderId, ?array $prepaid): void
    {
        if ($prepaid === null) {
            return;
        }
        DB::table('sales.order_payments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'order_id' => $orderId,
            'method' => $prepaid['method'], 'amount' => $prepaid['amount'], 'reference' => $prepaid['reference'] ?? null,
            'cash_session_id' => $this->cash->currentSessionId((string) request()->user()?->getAuthIdentifier()),
            'created_by' => request()->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * Valida las cantidades a entregar y arma las líneas de la venta.
     *
     * @param string $orderId Pedido.
     * @param list<array{line_id: string, qty: numeric-string}> $deliveries Cantidades por línea.
     * @return array{0: list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string}>, 1: list<array{0: stdClass, 1: numeric-string}>}
     */
    private function collectDeliveries(string $orderId, array $deliveries): array
    {
        $saleLines = [];
        $applied = [];
        foreach ($deliveries as $delivery) {
            $line = DB::table('sales.order_lines')->where('id', $delivery['line_id'])
                ->where('order_id', $orderId)->lockForUpdate()->first();
            if ($line === null) {
                throw new SaleException('line_not_found', 'Una línea no pertenece al pedido.', 422);
            }
            $pending = bcsub(Decimal::of($line->qty_ordered), Decimal::of($line->qty_delivered), 3);
            if (bccomp($delivery['qty'], $pending, 3) > 0) {
                throw new SaleException('qty_exceeds_pending', "La cantidad supera lo pendiente ({$pending}).", 422);
            }
            $saleLines[] = [
                'variant_id' => $line->variant_id, 'qty' => $delivery['qty'],
                'unit_price' => Decimal::of($line->unit_price), 'discount_pct' => Decimal::of($line->discount_pct),
            ];
            $applied[] = [$line, $delivery['qty']];
        }

        return [$saleLines, $applied];
    }

    /**
     * Libera la reserva correspondiente a lo entregado y actualiza las líneas.
     *
     * @param string $warehouseId Almacén.
     * @param list<array{0: stdClass, 1: numeric-string}> $applied Líneas y cantidades entregadas.
     */
    private function consumeReservations(string $warehouseId, array $applied): void
    {
        foreach ($applied as [$line, $qty]) {
            $reserved = Decimal::of($line->qty_reserved);
            $release = bccomp($reserved, $qty, 3) < 0 ? $reserved : $qty;
            if (bccomp($release, '0', 3) > 0) {
                $this->stock->release($warehouseId, $line->variant_id, $release);
            }
            DB::table('sales.order_lines')->where('id', $line->id)->update([
                'qty_delivered' => bcadd(Decimal::of($line->qty_delivered), $qty, 3),
                'qty_reserved' => bcsub($reserved, $release, 3),
            ]);
        }
    }

    /**
     * Bloquea y devuelve el pedido para modificarlo.
     *
     * @param string $orderId Pedido.
     */
    private function lock(string $orderId): stdClass
    {
        $order = DB::table('sales.orders')->where('id', $orderId)->lockForUpdate()->first();
        if ($order === null) {
            throw new SaleException('not_found', 'Pedido no encontrado.', 404);
        }

        return $order;
    }

    /**
     * Calcula el anticipo que se aplica a esta entrega: el menor entre el
     * anticipo disponible y el total de la venta.
     *
     * @param stdClass $order Pedido bloqueado.
     * @param list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string}> $saleLines Líneas a entregar.
     * @return numeric-string
     */
    private function advanceFor(stdClass $order, array $saleLines): string
    {
        [, $total] = $this->computeLines($saleLines, false);
        /** @var numeric-string $available */
        $available = bcsub(Decimal::of($order->prepaid_total), Decimal::of($order->prepaid_applied), 2);

        return bccomp($available, $total, 2) < 0 ? $available : $total;
    }

    /**
     * Cierra el pedido como entregado si todas las líneas están completas.
     *
     * @param string $orderId Pedido.
     */
    private function closeIfComplete(string $orderId): void
    {
        $pending = DB::table('sales.order_lines')->where('order_id', $orderId)
            ->whereColumn('qty_delivered', '<', 'qty_ordered')->exists();
        if (! $pending) {
            DB::table('sales.orders')->where('id', $orderId)->update(['status' => 'delivered']);
        }
    }

    /**
     * Calcula líneas y total del pedido con el calculador compatible con eFact.
     *
     * @param list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct?: numeric-string|null}> $lines Líneas.
     * @param bool $describe Si se debe resolver la descripción del producto.
     * @return array{0: list<array<string, mixed>>, 1: numeric-string} Líneas y total.
     */
    private function computeLines(array $lines, bool $describe = true): array
    {
        return $this->pricer->price($lines, $describe);
    }

    /**
     * Siguiente correlativo de pedido de la empresa.
     *
     * @param string $companyId Empresa.
     */
    private function nextNumber(string $companyId): int
    {
        return (int) DB::table('sales.orders')->where('company_id', $companyId)->max('number') + 1;
    }
}
