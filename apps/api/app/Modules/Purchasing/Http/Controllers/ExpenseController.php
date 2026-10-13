<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Application\CashService;
use App\Modules\Catalog\Models\Party;
use App\Modules\Platform\Models\Company;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Gastos operativos: registro, listado con total por rubro y anulación.
 */
class ExpenseController extends Controller
{
    /**
     * Registra un gasto; si sale efectivo, lo vincula al turno abierto del usuario.
     *
     * @param Request $request Datos del gasto.
     * @param CashService $cash Servicio de caja.
     */
    public function store(Request $request, CashService $cash): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', Rule::exists(Company::class, 'id')->where('status', 'active')],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'supplier_id' => ['nullable', 'uuid', Rule::exists(Party::class, 'id')],
            'method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $userId = (string) $request->user()?->getAuthIdentifier();
        $id = (string) Str::uuid();

        DB::table('purch.expenses')->insert([
            'id' => $id, 'tenant_id' => $request->user()?->tenant_id, 'company_id' => $data['company_id'],
            'category' => $data['category'], 'description' => $data['description'],
            'supplier_id' => $data['supplier_id'] ?? null, 'method' => $data['method'],
            'amount' => number_format((float) $data['amount'], 2, '.', ''),
            'cash_session_id' => $data['method'] === 'cash' ? $cash->currentSessionId($userId) : null,
            'created_by' => $userId, 'updated_by' => $userId,
        ]);

        return response()->json(['data' => DB::table('purch.expenses')->where('id', $id)->first()], 201);
    }

    /**
     * Lista los gastos recientes con el total por rubro.
     *
     * @param Request $request Admite `from` y `to` (fechas).
     */
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $query = DB::table('purch.expenses')->where('status', 'active');
        if (! empty($range['from'])) {
            $query->where('paid_at', '>=', Carbon::parse($range['from'])->startOfDay());
        }
        if (! empty($range['to'])) {
            $query->where('paid_at', '<=', Carbon::parse($range['to'])->endOfDay());
        }

        $totals = (clone $query)->selectRaw('category, sum(amount) as total')->groupBy('category')
            ->orderByDesc('total')->get();

        return response()->json([
            'data' => (clone $query)->orderByDesc('paid_at')->limit(200)->get(),
            'by_category' => $totals,
            'total' => Decimal::of($query->sum('amount')),
        ]);
    }

    /**
     * Anula un gasto (queda en el historial con su motivo).
     *
     * @param Request $request Datos: `reason`.
     * @param string $id Gasto.
     */
    public function void(Request $request, string $id): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $expense = DB::table('purch.expenses')->where('id', $id)->first();
        if ($expense === null) {
            throw new SaleException('not_found', 'Gasto no encontrado.', 404);
        }
        if ($expense->status === 'void') {
            throw new SaleException('invalid_transition', 'El gasto ya está anulado.', 409);
        }
        $this->assertSessionStillOpen($expense->cash_session_id);

        DB::table('purch.expenses')->where('id', $id)->update([
            'status' => 'void', 'void_reason' => $reason, 'updated_at' => now(),
            'updated_by' => $request->user()?->getAuthIdentifier(),
        ]);

        return response()->json(['data' => DB::table('purch.expenses')->where('id', $id)->first()]);
    }

    /**
     * No permite anular un gasto de efectivo cuyo turno ya se cerró.
     *
     * @param string|null $sessionId Turno vinculado.
     */
    private function assertSessionStillOpen(?string $sessionId): void
    {
        if ($sessionId !== null && DB::table('cash.cash_sessions')->where('id', $sessionId)->value('status') !== 'open') {
            throw new SaleException('session_closed', 'El turno de este gasto ya está cerrado.', 409);
        }
    }
}
