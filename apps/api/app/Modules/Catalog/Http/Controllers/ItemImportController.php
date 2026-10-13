<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Application\ItemImportService;
use App\Modules\Platform\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Importación masiva de productos desde un Excel leído en el navegador.
 */
class ItemImportController extends Controller
{
    /**
     * Valida o importa las filas recibidas.
     *
     * @param Request $request Datos: `rows`, `warehouse_id` y `dry_run`.
     * @param ItemImportService $import Servicio de importación.
     */
    public function store(Request $request, ItemImportService $import): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*' => ['array'],
            'warehouse_id' => ['nullable', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($data['rows']);
        $result = $import->import(
            (string) $request->user()?->tenant_id, $rows,
            $data['warehouse_id'] ?? null, (bool) ($data['dry_run'] ?? false),
        );

        return response()->json(['data' => $result]);
    }
}
