<?php

declare(strict_types=1);

it('el panel resume ventas del dia y por cobrar', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'yape', 'amount' => 236]],
    ])->assertCreated();

    $data = $this->withToken($token)->getJson('/api/v1/dashboard')->assertOk()->json('data');

    expect($data['sales_today']['count'])->toBe(1)
        ->and($data['sales_today']['total'])->toBe('236.00')
        ->and($data['receivables']['balance_total'])->toBe('0.00')
        ->and($data['top_products'])->toHaveCount(1)
        ->and($data['active_orders'])->toBe(0);
});
