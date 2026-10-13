<?php

declare(strict_types=1);

it('registra un gasto en efectivo y descuenta del turno de caja', function (): void {
    $token = ownerToken();
    $f = cashFixture($token);
    $company = $this->withToken($token)->getJson('/api/v1/companies')->json('data.0.id');
    $session = $this->withToken($token)->postJson('/api/v1/cash-sessions', [
        'cash_register_id' => $f['register'], 'opening_amount' => 100,
    ])->json('data');

    $expense = $this->withToken($token)->postJson('/api/v1/expenses', [
        'company_id' => $company, 'category' => 'Servicios', 'description' => 'Recibo de luz',
        'method' => 'cash', 'amount' => 35.5,
    ])->assertCreated()->json('data');

    expect($expense->cash_session_id ?? $expense['cash_session_id'])->toBe($session['id']);
    $summary = $this->withToken($token)->getJson("/api/v1/cash-sessions/{$session['id']}")->json('data');
    expect($summary['expected_cash'])->toBe('64.50');

    $this->withToken($token)->postJson('/api/v1/expenses/'.($expense->id ?? $expense['id']).'/void', ['reason' => 'Error de monto'])
        ->assertOk()->assertJsonPath('data.status', 'void');
    $summary = $this->withToken($token)->getJson("/api/v1/cash-sessions/{$session['id']}")->json('data');
    expect($summary['expected_cash'])->toBe('100.00');
});

it('totaliza los gastos por rubro', function (): void {
    $token = ownerToken();
    saleFixture($token);
    $company = $this->withToken($token)->getJson('/api/v1/companies')->json('data.0.id');
    foreach ([['Servicios', 40], ['Servicios', 60], ['Alquiler', 500]] as [$category, $amount]) {
        $this->withToken($token)->postJson('/api/v1/expenses', [
            'company_id' => $company, 'category' => $category, 'description' => 'Pago',
            'method' => 'transfer', 'amount' => $amount,
        ])->assertCreated();
    }

    $list = $this->withToken($token)->getJson('/api/v1/expenses')->assertOk()->json();

    expect((float) $list['total'])->toBe(600.0)->and($list['by_category'])->toHaveCount(2)
        ->and($list['by_category'][0]['category'])->toBe('Alquiler');
});
