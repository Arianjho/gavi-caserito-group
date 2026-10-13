<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Platform\Models\Warehouse;
use App\Shared\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Toma física: se registra lo contado y el sistema ajusta las diferencias
 * contra el saldo, dejando un movimiento por cada producto que difiere.
 */
class CountController extends Controller
{
    /**
     * Aplica una toma física de un almacén.
     *
     * @param Request $request Datos: `warehouse_id`, `reason` y `lines` con la cantidad contada.
     * @param StockService $stock Servicio de stock.
     */
    public function store(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')],
            'lines.*.counted' => ['required', 'numeric', 'min:0'],
        ]);

        $documentId = (string) Str::uuid();
        $context = [
            'source_type' => 'count', 'source_id' => $documentId, 'reason' => $data['reason'] ?? 'Toma física',
        ];

        $differences = DB::transaction(function () use ($data, $stock, $context): array {
            $out = [];
            foreach ($data['lines'] as $line) {
                $current = Decimal::of(DB::table('inv.stock_balances')
                    ->where('warehouse_id', $data['warehouse_id'])->where('variant_id', $line['variant_id'])->value('on_hand') ?? 0);
                $diff = bcsub(Decimal::of($line['counted']), $current, 3);
                if (bccomp($diff, '0', 3) === 0) {
                    continue;
                }
                $type = bccomp($diff, '0', 3) > 0 ? 'adjustment_in' : 'adjustment_out';
                /** @var numeric-string $qty */
                $qty = ltrim($diff, '-');
                $stock->post($data['warehouse_id'], $line['variant_id'], $type, $qty, null, $context);
                $out[] = ['variant_id' => $line['variant_id'], 'system' => $current, 'counted' => $line['counted'], 'difference' => $diff];
            }

            return $out;
        });

        return response()->json([
            'data' => ['document_id' => $documentId, 'checked' => count($data['lines']), 'adjusted' => $differences],
        ], 201);
    }
}
