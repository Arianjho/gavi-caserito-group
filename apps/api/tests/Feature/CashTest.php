<?php

declare(strict_types=1);

/**
 * Crea una caja en la sucursal del fixture de venta.
 *
 * @param string $token Token del propietario.
 * @param bool $requiresSession Si exige turno.
 * @return array{warehouse: string, variant: string, register: string}
 */
function cashFixture(string $token, bool $requiresSession = true): array
{
    $f = saleFixture($token);
    $branch = test()->withToken($token)->getJson('/api/v1/warehouses')->json('data.0.branch_id');
    $register = test()->withToken($token)->postJson('/api/v1/cash-registers', [
        'branch_id' => $branch, 'name' => 'Caja 1', 'requires_session' => $requiresSession,
    ])->assertCreated()->json('data.id');

    return $f + ['register' => $register];
}

it('exige turno abierto para cobrar en efectivo y lo cierra con arqueo', function (): void {
    $token = ownerToken();
    $f = cashFixture($token);
    $sale = ['warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 118]]];

    $this->withToken($token)->postJson('/api/v1/sales', $sale)
        ->assertStatus(422)->assertJsonPath('code', 'cash_session_required');

    $session = $this->withToken($token)->postJson('/api/v1/cash-sessions', [
        'cash_register_id' => $f['register'], 'opening_amount' => 50,
    ])->assertCreated()->json('data');

    $this->withToken($token)->postJson('/api/v1/sales', $sale)->assertCreated();
    $this->withToken($token)->postJson("/api/v1/cash-sessions/{$session['id']}/movements", [
        'kind' => 'out', 'amount' => 20, 'reason' => 'Compra de bolsas',
    ])->assertCreated();

    $summary = $this->withToken($token)->getJson('/api/v1/cash-sessions/current')->json('data');
    expect($summary['expected_cash'])->toBe('148.00')
        ->and($summary['collections_by_method']['cash'])->toBe('118.00');

    $closed = $this->withToken($token)->postJson("/api/v1/cash-sessions/{$session['id']}/close", [
        'counted_amount' => 147,
    ])->assertOk()->json('data');

    expect($closed['status'])->toBe('closed')->and($closed['difference'])->toBe('-1.00');
});

it('no permite dos turnos abiertos en la misma caja', function (): void {
    $token = ownerToken();
    $f = cashFixture($token);
    $this->withToken($token)->postJson('/api/v1/cash-sessions', ['cash_register_id' => $f['register'], 'opening_amount' => 0])
        ->assertCreated();
    $this->withToken($token)->postJson('/api/v1/cash-sessions', ['cash_register_id' => $f['register'], 'opening_amount' => 0])
        ->assertStatus(409)->assertJsonPath('code', 'session_already_open');
});

it('una caja sin turno obligatorio permite cobrar sin abrirlo', function (): void {
    $token = ownerToken();
    $f = cashFixture($token, false);

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 118]],
    ])->assertCreated();
});
