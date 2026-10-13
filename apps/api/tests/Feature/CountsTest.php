<?php

declare(strict_types=1);

it('ajusta las diferencias de una toma fisica y omite lo que coincide', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $result = $this->withToken($token)->postJson('/api/v1/inventory/counts', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'counted' => 7]],
    ])->assertCreated()->json('data');

    expect($result['adjusted'])->toHaveCount(1)->and((float) $result['adjusted'][0]['difference'])->toBe(-3.0);
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(7.0);

    $again = $this->withToken($token)->postJson('/api/v1/inventory/counts', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'counted' => 7]],
    ])->assertCreated()->json('data');
    expect($again['adjusted'])->toHaveCount(0);
});

it('un conteo mayor al saldo genera una entrada de ajuste', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $this->withToken($token)->postJson('/api/v1/inventory/counts', [
        'warehouse_id' => $f['warehouse'], 'lines' => [['variant_id' => $f['variant'], 'counted' => 12]],
    ])->assertCreated();

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(12.0);
});
