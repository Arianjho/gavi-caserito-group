<?php

declare(strict_types=1);

/**
 * Registra una venta de 2 unidades pagada en efectivo.
 *
 * @param array{warehouse: string, variant: string} $f Datos base.
 * @param string $token Token del propietario.
 * @return array<string, mixed>
 */
function paidSale(array $f, string $token): array
{
    return test()->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 236]],
    ])->assertCreated()->json('data');
}

it('devuelve parte de una venta: reingresa stock y descuenta el efectivo del turno', function (): void {
    $token = ownerToken();
    $f = cashFixture($token);
    $session = $this->withToken($token)->postJson('/api/v1/cash-sessions', [
        'cash_register_id' => $f['register'], 'opening_amount' => 0,
    ])->json('data');
    $sale = paidSale($f, $token);
    $lineId = $sale['lines'][0]['id'];

    $return = $this->withToken($token)->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['line_id' => $lineId, 'qty' => 1]], 'refund_method' => 'cash', 'reason' => 'Talla incorrecta',
    ])->assertCreated()->json('data');

    expect($return['total'])->toBe('118.00')->and($return['net_value'])->toBe('100.00');
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(9.0);
    $summary = $this->withToken($token)->getJson("/api/v1/cash-sessions/{$session['id']}")->json('data');
    expect($summary['expected_cash'])->toBe('118.00');
});

it('no permite devolver mas de lo vendido y cierra con el saldo exacto', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $sale = paidSale(['warehouse' => $f['warehouse'], 'variant' => $f['variant']], $token);
    $lineId = $sale['lines'][0]['id'];
    $url = "/api/v1/sales/{$sale['id']}/returns";

    $this->withToken($token)->postJson($url, [
        'lines' => [['line_id' => $lineId, 'qty' => 3]], 'refund_method' => 'yape', 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'qty_exceeds_sold');

    $this->withToken($token)->postJson($url, [
        'lines' => [['line_id' => $lineId, 'qty' => 1]], 'refund_method' => 'yape', 'reason' => 'x',
    ])->assertCreated();
    $second = $this->withToken($token)->postJson($url, [
        'lines' => [['line_id' => $lineId, 'qty' => 1]], 'refund_method' => 'yape', 'reason' => 'x',
    ])->assertCreated()->json('data');

    expect($second['total'])->toBe('118.00');
    $this->withToken($token)->getJson($url)->assertJsonCount(2, 'data');
    $report = $this->withToken($token)->getJson('/api/v1/reports/sales')->json('data');
    expect($report['returns'])->toBe('236.00')->and($report['revenue_net'])->toBe('0.00');
});

it('no devuelve una venta a credito con saldo pendiente', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $sale = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'], 'on_credit' => true, 'due_date' => now()->addDays(10)->toDateString(),
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]], 'payments' => [],
    ])->assertCreated()->json('data');

    $this->withToken($token)->postJson("/api/v1/sales/{$sale['id']}/returns", [
        'lines' => [['line_id' => $sale['lines'][0]['id'], 'qty' => 1]], 'refund_method' => 'cash', 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'balance_pending');
});
