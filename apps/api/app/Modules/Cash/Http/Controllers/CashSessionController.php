<?php

declare(strict_types=1);

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Application\CashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Turnos de caja: consulta del turno actual, apertura, movimientos y cierre.
 */
class CashSessionController extends Controller
{
    /**
     * Turno abierto del usuario (o `null`) con su resumen.
     *
     * @param Request $request Petición autenticada.
     * @param CashService $cash Servicio de caja.
     */
    public function current(Request $request, CashService $cash): JsonResponse
    {
        $id = $cash->currentSessionId((string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => $id === null ? null : $cash->summary($id)]);
    }

    /**
     * Lista los turnos recientes con su caja.
     */
    public function index(): JsonResponse
    {
        $rows = DB::table('cash.cash_sessions as s')
            ->join('cash.cash_registers as r', 'r.id', '=', 's.cash_register_id')
            ->orderByDesc('s.opened_at')->limit(100)
            ->get(['s.id', 'r.name as register_name', 's.status', 's.opened_at', 's.closed_at',
                's.opening_amount', 's.expected_amount', 's.counted_amount', 's.difference']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Abre un turno.
     *
     * @param Request $request Datos: `cash_register_id` y `opening_amount`.
     * @param CashService $cash Servicio de caja.
     */
    public function open(Request $request, CashService $cash): JsonResponse
    {
        $data = $request->validate([
            'cash_register_id' => ['required', 'uuid'],
            'opening_amount' => ['required', 'numeric', 'min:0'],
        ]);
        $amount = number_format((float) $data['opening_amount'], 2, '.', '');
        assert(is_numeric($amount));

        $summary = $cash->open(
            (string) $request->user()?->tenant_id,
            (string) $request->user()?->getAuthIdentifier(),
            $data['cash_register_id'],
            $amount,
        );

        return response()->json(['data' => $summary], 201);
    }

    /**
     * Registra un ingreso o egreso en el turno.
     *
     * @param Request $request Datos: `kind`, `amount`, `reason`.
     * @param string $id Turno.
     * @param CashService $cash Servicio de caja.
     */
    public function movement(Request $request, string $id, CashService $cash): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'min:3', 'max:120'],
        ]);
        $amount = number_format((float) $data['amount'], 2, '.', '');
        assert(is_numeric($amount));

        $summary = $cash->addMovement(
            (string) $request->user()?->tenant_id,
            (string) $request->user()?->getAuthIdentifier(),
            $id,
            $data['kind'],
            $amount,
            $data['reason'],
        );

        return response()->json(['data' => $summary], 201);
    }

    /**
     * Cierra el turno con el efectivo contado.
     *
     * @param Request $request Datos: `counted_amount` y `notes`.
     * @param string $id Turno.
     * @param CashService $cash Servicio de caja.
     */
    public function close(Request $request, string $id, CashService $cash): JsonResponse
    {
        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $counted = number_format((float) $data['counted_amount'], 2, '.', '');
        assert(is_numeric($counted));

        return response()->json([
            'data' => $cash->close((string) $request->user()?->getAuthIdentifier(), $id, $counted, $data['notes'] ?? null),
        ]);
    }

    /**
     * Muestra el resumen de un turno.
     *
     * @param string $id Turno.
     * @param CashService $cash Servicio de caja.
     */
    public function show(string $id, CashService $cash): JsonResponse
    {
        return response()->json(['data' => $cash->summary($id)]);
    }
}
