<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\SaleReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Devoluciones de una venta.
 */
class SaleReturnController extends Controller
{
    /**
     * Registra una devolución parcial o total.
     *
     * @param Request $request Datos: `lines`, `refund_method` y `reason`.
     * @param string $id Venta.
     * @param SaleReturnService $returns Servicio de devoluciones.
     */
    public function store(Request $request, string $id, SaleReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'refund_method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        /** @var list<array{line_id: string, qty: numeric-string}> $lines */
        $lines = array_map(fn (array $l): array => [
            'line_id' => $l['line_id'], 'qty' => (string) $l['qty'],
        ], $data['lines']);

        return response()->json(
            ['data' => $returns->create($id, $lines, $data['refund_method'], $data['reason'])],
            201,
        );
    }

    /**
     * Lista las devoluciones de una venta.
     *
     * @param string $id Venta.
     * @param SaleReturnService $returns Servicio de devoluciones.
     */
    public function index(string $id, SaleReturnService $returns): JsonResponse
    {
        return response()->json(['data' => $returns->forSale($id)]);
    }
}
