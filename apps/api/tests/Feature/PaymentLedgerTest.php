<?php

declare(strict_types=1);

it('lista los cobros con su total y filtra por medio', function (): void {
    $token = ownerToken();
    $f = cashFixture($token);
    $this->withToken($token)->postJson('/api/v1/cash-sessions', [
        'cash_register_id' => $f['register'], 'opening_amount' => 0,
    ])->assertCreated();
    paidSale($f, $token);

    $all = $this->withToken($token)->getJson('/api/v1/collections')->assertOk()->json();
    expect($all['data'])->toHaveCount(1)->and($all['meta']['total'])->toBe('236.00');

    $this->withToken($token)->getJson('/api/v1/collections?method=yape')->assertJsonCount(0, 'data');
    $this->withToken($token)->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', '0.00');
});
