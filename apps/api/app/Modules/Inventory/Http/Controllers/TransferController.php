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
 * Traslados de mercadería entre almacenes: salen al costo promedio del
 * origen y entran al destino con ese mismo costo.
 */
class TransferController extends Controller
{
    /**
     * Registra un traslado con varias líneas, de forma atómica.
     *
     * @param Request $request Datos: `from_warehouse_id`, `to_warehouse_id`, `reason` y `lines`.
     * @param StockService $stock Servicio de stock.
     */
    public function store(Request $request, StockService $stock): JsonResponse
    {
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'to_warehouse_id' => ['required', 'uuid', 'different:from_warehouse_id',
                Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'reason' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
        ]);

        $documentId = (string) Str::uuid();
        $context = ['source_type' => 'transfer', 'source_id' => $documentId, 'reason' => $data['reason'] ?? 'Traslado entre almacenes'];

        DB::transaction(function () use ($data, $stock, $context): void {
            foreach ($data['lines'] as $line) {
                /** @var numeric-string $qty */
                $qty = (string) $line['qty'];
                $out = $stock->post($data['from_warehouse_id'], $line['variant_id'], 'transfer_out', $qty, null, $context);
                $stock->post($data['to_warehouse_id'], $line['variant_id'], 'transfer_in', $qty, $out['avg_cost'], $context);
            }
        });

        return response()->json(['data' => ['document_id' => $documentId, 'lines' => count($data['lines'])]], 201);
    }

    /**
     * Lista los traslados recientes agrupados por documento.
     */
    public function index(): JsonResponse
    {
        $rows = DB::table('inv.stock_movements as m')
            ->join('core.warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->join('cat.item_variants as v', 'v.id', '=', 'm.variant_id')
            ->join('cat.items as i', 'i.id', '=', 'v.item_id')
            ->where('m.source_type', 'transfer')->where('m.movement_type', 'transfer_out')
            ->orderByDesc('m.occurred_at')->limit(200)
            ->get(['m.source_id as document_id', 'w.name as from_warehouse', 'i.name', 'v.name as variant_name',
                'm.qty', 'm.reason', 'm.occurred_at']);

        return response()->json(['data' => $rows]);
    }
}
