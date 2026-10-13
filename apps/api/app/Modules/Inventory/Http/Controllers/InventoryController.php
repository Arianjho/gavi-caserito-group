<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Platform\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Consulta de existencias y kardex, y registro de saldos iniciales y ajustes.
 */
class InventoryController extends Controller
{
    /**
     * Registra un saldo inicial o un ajuste con varias líneas, de forma atómica.
     *
     * Si una línea deja stock negativo (y no se permite), no se aplica ninguna.
     *
     * @param Request $request Datos: `warehouse_id`, `type`, `reason` y `lines`.
     * @param StockService $stock Servicio de stock.
     */
    public function adjust(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'type' => ['required', Rule::in(['opening', 'adjustment_in', 'adjustment_out'])],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $documentId = (string) Str::uuid();

        $results = DB::transaction(function () use ($data, $stock, $documentId): array {
            $out = [];
            foreach ($data['lines'] as $line) {
                /** @var numeric-string $qty */
                $qty = (string) $line['qty'];
                /** @var numeric-string|null $cost */
                $cost = isset($line['unit_cost']) ? (string) $line['unit_cost'] : null;
                $out[] = $stock->post(
                    $data['warehouse_id'],
                    $line['variant_id'],
                    $data['type'],
                    $qty,
                    $cost,
                    ['source_type' => 'adjustment', 'source_id' => $documentId, 'reason' => $data['reason'] ?? null],
                );
            }

            return $out;
        });

        return response()->json(['data' => ['document_id' => $documentId, 'lines' => $results]], 201);
    }

    /**
     * Existencias por almacén y variante.
     *
     * @param Request $request Admite `warehouse_id` y `q` (nombre, SKU o código).
     */
    public function stock(Request $request): JsonResponse
    {
        $query = DB::table('inv.stock_balances as b')
            ->join('cat.item_variants as v', 'v.id', '=', 'b.variant_id')
            ->join('cat.items as i', 'i.id', '=', 'v.item_id')
            ->join('core.warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->orderBy('i.name')
            ->select([
                'b.warehouse_id', 'w.name as warehouse_name', 'b.variant_id', 'i.name as item_name',
                'v.sku', 'b.on_hand', 'b.reserved', 'b.avg_cost', 'b.last_movement_at',
            ]);

        if ($request->filled('warehouse_id')) {
            $query->where('b.warehouse_id', $request->query('warehouse_id'));
        }
        if ($request->filled('q')) {
            $like = '%'.mb_strtolower((string) $request->query('q')).'%';
            $query->where(fn ($q) => $q->whereRaw('lower(i.name) like ?', [$like])->orWhereRaw('lower(v.sku) like ?', [$like]));
        }

        return response()->json(['data' => $query->limit(500)->get()]);
    }

    /**
     * Kardex: movimientos de una variante (y opcionalmente de un almacén).
     *
     * @param Request $request Requiere `variant_id`; admite `warehouse_id`.
     */
    public function movements(Request $request): JsonResponse
    {
        $request->validate(['variant_id' => ['required', 'uuid']]);

        $query = DB::table('inv.stock_movements as m')
            ->join('core.warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->where('m.variant_id', $request->query('variant_id'))
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')
            ->select([
                'm.id', 'm.warehouse_id', 'w.name as warehouse_name', 'm.movement_type', 'm.direction', 'm.qty',
                'm.unit_cost', 'm.total_cost', 'm.balance_after', 'm.reason', 'm.occurred_at',
            ]);

        if ($request->filled('warehouse_id')) {
            $query->where('m.warehouse_id', $request->query('warehouse_id'));
        }

        return response()->json(['data' => $query->limit(200)->get()]);
    }
}
