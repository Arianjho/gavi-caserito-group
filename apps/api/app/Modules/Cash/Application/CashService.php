<?php

declare(strict_types=1);

namespace App\Modules\Cash\Application;

use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turnos de caja: apertura, movimientos, resumen y cierre con arqueo.
 *
 * Efectivo esperado = apertura + cobros en efectivo del turno + ingresos − egresos.
 */
final class CashService
{
    /**
     * Abre un turno para el usuario en una caja.
     *
     * @param string $tenantId Cuenta.
     * @param string $userId Usuario que abre.
     * @param string $registerId Caja.
     * @param numeric-string $openingAmount Efectivo inicial.
     * @return array<string, mixed> Resumen del turno.
     *
     * @throws SaleException Si la caja o el usuario ya tienen un turno abierto.
     */
    public function open(string $tenantId, string $userId, string $registerId, string $openingAmount): array
    {
        $register = DB::table('cash.cash_registers')->where('id', $registerId)->where('status', 'active')->first();
        if ($register === null) {
            throw new SaleException('register_not_found', 'La caja no existe o está inactiva.', 404);
        }
        $busy = DB::table('cash.cash_sessions')->where('status', 'open')
            ->where(fn ($q) => $q->where('cash_register_id', $registerId)->orWhere('opened_by', $userId))->exists();
        if ($busy) {
            throw new SaleException('session_already_open', 'Ya hay un turno abierto en esta caja o para este usuario.', 409);
        }

        $id = (string) Str::uuid();
        DB::table('cash.cash_sessions')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'cash_register_id' => $registerId,
            'opened_by' => $userId, 'opening_amount' => $openingAmount,
            'created_by' => $userId, 'updated_by' => $userId,
        ]);

        return $this->summary($id);
    }

    /**
     * Turno abierto del usuario, si lo tiene.
     *
     * @param string $userId Usuario.
     */
    public function currentSessionId(string $userId): ?string
    {
        $id = DB::table('cash.cash_sessions')->where('status', 'open')->where('opened_by', $userId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Exige un turno abierto si la sucursal tiene una caja que lo requiere y hay cobro en efectivo.
     *
     * @param string $branchId Sucursal de la operación.
     * @param string|null $userId Usuario que cobra.
     * @param bool $hasCash Si la operación incluye efectivo.
     *
     * @throws SaleException Si falta el turno.
     */
    public function assertSessionIfRequired(string $branchId, ?string $userId, bool $hasCash): void
    {
        if (! $hasCash) {
            return;
        }
        $required = DB::table('cash.cash_registers')->where('branch_id', $branchId)
            ->where('status', 'active')->where('requires_session', true)->exists();
        if ($required && ($userId === null || $this->currentSessionId($userId) === null)) {
            throw new SaleException('cash_session_required', 'Abre tu turno de caja antes de cobrar en efectivo.', 422);
        }
    }

    /**
     * Registra un ingreso o egreso de caja chica.
     *
     * @param string $tenantId Cuenta.
     * @param string $userId Usuario.
     * @param string $sessionId Turno abierto.
     * @param string $kind `in` o `out`.
     * @param numeric-string $amount Importe.
     * @param string $reason Motivo.
     * @return array<string, mixed> Resumen del turno.
     */
    public function addMovement(string $tenantId, string $userId, string $sessionId, string $kind, string $amount, string $reason): array
    {
        $this->assertOpen($sessionId);
        DB::table('cash.cash_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'cash_session_id' => $sessionId,
            'kind' => $kind, 'amount' => $amount, 'reason' => $reason, 'created_by' => $userId,
        ]);

        return $this->summary($sessionId);
    }

    /**
     * Cierra el turno calculando esperado y diferencia.
     *
     * @param string $userId Usuario que cierra.
     * @param string $sessionId Turno.
     * @param numeric-string $counted Efectivo contado.
     * @param string|null $notes Observaciones.
     * @return array<string, mixed> Resumen del turno cerrado.
     */
    public function close(string $userId, string $sessionId, string $counted, ?string $notes): array
    {
        return DB::transaction(function () use ($userId, $sessionId, $counted, $notes): array {
            DB::table('cash.cash_sessions')->where('id', $sessionId)->lockForUpdate()->first();
            $this->assertOpen($sessionId);
            $expected = $this->summary($sessionId)['expected_cash'];

            DB::table('cash.cash_sessions')->where('id', $sessionId)->update([
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => $userId,
                'expected_amount' => $expected, 'counted_amount' => $counted,
                'difference' => bcsub($counted, Decimal::of($expected), 2), 'close_notes' => $notes,
                'updated_at' => now(), 'updated_by' => $userId,
            ]);

            return $this->summary($sessionId);
        });
    }

    /**
     * Resumen del turno: apertura, cobros por medio, movimientos y efectivo esperado.
     *
     * @param string $sessionId Turno.
     * @return array<string, mixed>
     */
    public function summary(string $sessionId): array
    {
        $session = DB::table('cash.cash_sessions')->where('id', $sessionId)->first();
        if ($session === null) {
            throw new SaleException('not_found', 'Turno no encontrado.', 404);
        }

        $byMethod = DB::table('sales.document_payments')->where('cash_session_id', $sessionId)
            ->selectRaw('method, sum(amount) as total')->groupBy('method')->pluck('total', 'method')->all();
        $advances = DB::table('sales.order_payments')->where('cash_session_id', $sessionId)
            ->selectRaw('method, sum(amount) as total')->groupBy('method')->pluck('total', 'method')->all();
        foreach ($advances as $method => $total) {
            $byMethod[$method] = bcadd(Decimal::of($byMethod[$method] ?? 0), Decimal::of($total), 2);
        }
        unset($byMethod['advance']);

        $in = Decimal::of(DB::table('cash.cash_movements')->where('cash_session_id', $sessionId)->where('kind', 'in')->sum('amount'));
        $out = Decimal::of(DB::table('cash.cash_movements')->where('cash_session_id', $sessionId)->where('kind', 'out')->sum('amount'));
        $cash = Decimal::of($byMethod['cash'] ?? 0);
        $supplierPaid = Decimal::of(DB::table('purch.purchase_payments')
            ->where('cash_session_id', $sessionId)->where('method', 'cash')->sum('amount'));
        $expensesPaid = Decimal::of(DB::table('purch.expenses')->where('cash_session_id', $sessionId)
            ->where('method', 'cash')->where('status', 'active')->sum('amount'));
        $out = bcadd(bcadd($out, $supplierPaid, 2), $expensesPaid, 2);
        $expected = bcsub(bcadd(bcadd(Decimal::of($session->opening_amount), $cash, 2), $in, 2), $out, 2);

        return (array) $session + [
            'collections_by_method' => $byMethod,
            'movements_in' => bcadd($in, '0', 2),
            'movements_out' => bcadd($out, '0', 2),
            'expected_cash' => $expected,
            'movements' => DB::table('cash.cash_movements')->where('cash_session_id', $sessionId)
                ->orderBy('occurred_at')->get()->all(),
        ];
    }

    /**
     * Verifica que el turno esté abierto.
     *
     * @param string $sessionId Turno.
     */
    private function assertOpen(string $sessionId): void
    {
        $status = DB::table('cash.cash_sessions')->where('id', $sessionId)->value('status');
        if ($status === null) {
            throw new SaleException('not_found', 'Turno no encontrado.', 404);
        }
        if ($status !== 'open') {
            throw new SaleException('session_closed', 'El turno ya está cerrado.', 409);
        }
    }
}
