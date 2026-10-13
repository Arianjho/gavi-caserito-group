<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Platform\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Registra movimientos de stock y mantiene los saldos y el costo promedio.
 *
 * Es la única vía de escritura del inventario: cada operación inserta filas
 * en el libro de movimientos (inmutable) y actualiza el saldo del almacén
 * bajo bloqueo de fila, de modo que dos operaciones simultáneas no se pisan.
 */
final class StockService
{
    /**
     * Tipos de movimiento que aumentan el stock.
     *
     * @var list<string>
     */
    private const INBOUND = ['opening', 'adjustment_in', 'purchase', 'sale_return', 'transfer_in'];

    /**
     * Registra un movimiento y devuelve el saldo resultante.
     *
     * @param string $warehouseId Almacén afectado.
     * @param string $variantId Variante afectada.
     * @param string $type Tipo de movimiento (`opening`, `adjustment_out`, `sale`...).
     * @param numeric-string $qty Cantidad positiva en unidad base (decimal como texto).
     * @param numeric-string|null $unitCost Costo unitario de entrada; en salidas se usa el costo promedio.
     * @param array{source_type?: string|null, source_id?: string|null, reason?: string|null} $context Documento origen y motivo.
     * @return array{balance: numeric-string, avg_cost: numeric-string, movement_id: string}
     *
     * @throws StockInsufficientException Si la salida deja stock negativo y no está permitido.
     */
    public function post(
        string $warehouseId,
        string $variantId,
        string $type,
        string $qty,
        ?string $unitCost = null,
        array $context = [],
    ): array {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        [$onHand, $avgCost] = $this->lockBalance($warehouse, $variantId);
        $inbound = in_array($type, self::INBOUND, true);

        [$newOnHand, $newAvg, $cost] = $inbound
            ? $this->applyInbound($onHand, $avgCost, $qty, $unitCost ?? $avgCost)
            : $this->applyOutbound($warehouse, $variantId, $onHand, $avgCost, $qty);

        $movementId = $this->insertMovement($warehouse, $variantId, $type, $inbound, $qty, $cost, $newOnHand, $context);

        DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)
            ->update(['on_hand' => $newOnHand, 'avg_cost' => $newAvg, 'last_movement_at' => now()]);

        return ['balance' => $newOnHand, 'avg_cost' => $newAvg, 'movement_id' => $movementId];
    }

    /**
     * Crea (si falta) y bloquea el saldo de la variante en el almacén.
     *
     * @param Warehouse $warehouse Almacén.
     * @param string $variantId Variante.
     * @return array{0: numeric-string, 1: numeric-string} Existencias y costo promedio actuales.
     */
    private function lockBalance(Warehouse $warehouse, string $variantId): array
    {
        DB::table('inv.stock_balances')->insertOrIgnore([
            'tenant_id' => $warehouse->tenant_id,
            'warehouse_id' => $warehouse->id,
            'variant_id' => $variantId,
        ]);

        $balance = DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouse->id)->where('variant_id', $variantId)
            ->lockForUpdate()->first();

        if ($balance === null) {
            throw new LogicException('No se pudo bloquear el saldo de stock.');
        }

        /** @var numeric-string $onHand */
        $onHand = (string) $balance->on_hand;
        /** @var numeric-string $avgCost */
        $avgCost = (string) $balance->avg_cost;

        return [$onHand, $avgCost];
    }

    /**
     * Calcula existencias y costo promedio ponderado tras una entrada.
     *
     * @param numeric-string $onHand Existencias antes.
     * @param numeric-string $avgCost Costo promedio antes.
     * @param numeric-string $qty Cantidad que entra.
     * @param numeric-string $cost Costo unitario de la entrada.
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string} Nuevas existencias, nuevo costo promedio y costo aplicado.
     */
    private function applyInbound(string $onHand, string $avgCost, string $qty, string $cost): array
    {
        $newOnHand = bcadd($onHand, $qty, 3);
        $newAvg = bccomp($newOnHand, '0', 3) > 0
            ? bcdiv(bcadd(bcmul($onHand, $avgCost, 6), bcmul($qty, $cost, 6), 6), $newOnHand, 4)
            : $cost;

        return [$newOnHand, $newAvg, $cost];
    }

    /**
     * Calcula existencias tras una salida; el costo promedio no cambia.
     *
     * @param Warehouse $warehouse Almacén.
     * @param string $variantId Variante.
     * @param numeric-string $onHand Existencias antes.
     * @param numeric-string $avgCost Costo promedio actual.
     * @param numeric-string $qty Cantidad que sale.
     * @return array{0: numeric-string, 1: numeric-string, 2: numeric-string} Nuevas existencias, costo promedio y costo aplicado.
     *
     * @throws StockInsufficientException Si el saldo quedaría negativo y no se permite.
     */
    private function applyOutbound(Warehouse $warehouse, string $variantId, string $onHand, string $avgCost, string $qty): array
    {
        $newOnHand = bcsub($onHand, $qty, 3);

        if (bccomp($newOnHand, '0', 3) < 0 && $warehouse->allows_negative !== true) {
            throw new StockInsufficientException($variantId, $onHand, $qty);
        }

        return [$newOnHand, $avgCost, $avgCost];
    }

    /**
     * Inserta el movimiento en el libro y devuelve su identificador.
     *
     * @param Warehouse $warehouse Almacén.
     * @param string $variantId Variante.
     * @param string $type Tipo de movimiento.
     * @param bool $inbound Si es una entrada.
     * @param numeric-string $qty Cantidad.
     * @param numeric-string $cost Costo unitario aplicado.
     * @param numeric-string $balanceAfter Saldo después del movimiento.
     * @param array{source_type?: string|null, source_id?: string|null, reason?: string|null} $context Documento origen y motivo.
     */
    private function insertMovement(
        Warehouse $warehouse,
        string $variantId,
        string $type,
        bool $inbound,
        string $qty,
        string $cost,
        string $balanceAfter,
        array $context,
    ): string {
        $id = (string) Str::uuid();

        DB::table('inv.stock_movements')->insert([
            'id' => $id,
            'tenant_id' => $warehouse->tenant_id,
            'company_id' => $warehouse->company_id,
            'warehouse_id' => $warehouse->id,
            'variant_id' => $variantId,
            'movement_type' => $type,
            'direction' => $inbound ? 'in' : 'out',
            'qty' => $qty,
            'unit_cost' => $cost,
            'total_cost' => bcmul($qty, $cost, 4),
            'balance_after' => $balanceAfter,
            'source_type' => $context['source_type'] ?? null,
            'source_id' => $context['source_id'] ?? null,
            'reason' => $context['reason'] ?? null,
            'created_by' => request()->user()?->getAuthIdentifier(),
        ]);

        return $id;
    }

    /**
     * Reserva stock para un pedido (aumenta `reserved` sin mover existencias).
     *
     * @param string $warehouseId Almacén.
     * @param string $variantId Variante.
     * @param numeric-string $qty Cantidad a reservar.
     *
     * @throws StockInsufficientException Si lo disponible no alcanza y no se permite negativo.
     */
    public function reserve(string $warehouseId, string $variantId, string $qty): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);
        $this->lockBalance($warehouse, $variantId);

        $row = DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)->first();
        if ($row === null) {
            throw new LogicException('No se pudo leer el saldo de stock.');
        }

        /** @var numeric-string $onHand */
        $onHand = (string) $row->on_hand;
        /** @var numeric-string $reserved */
        $reserved = (string) $row->reserved;
        $available = bcsub($onHand, $reserved, 3);

        if (bccomp($available, $qty, 3) < 0 && $warehouse->allows_negative !== true) {
            throw new StockInsufficientException($variantId, $available, $qty);
        }

        DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)
            ->update(['reserved' => bcadd($reserved, $qty, 3)]);
    }

    /**
     * Libera stock reservado (no baja de cero).
     *
     * @param string $warehouseId Almacén.
     * @param string $variantId Variante.
     * @param numeric-string $qty Cantidad a liberar.
     */
    public function release(string $warehouseId, string $variantId, string $qty): void
    {
        $row = DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)
            ->lockForUpdate()->first();
        if ($row === null) {
            return;
        }

        /** @var numeric-string $reserved */
        $reserved = (string) $row->reserved;
        $next = bcsub($reserved, $qty, 3);

        DB::table('inv.stock_balances')
            ->where('warehouse_id', $warehouseId)->where('variant_id', $variantId)
            ->update(['reserved' => bccomp($next, '0', 3) < 0 ? '0.000' : $next]);
    }
}
