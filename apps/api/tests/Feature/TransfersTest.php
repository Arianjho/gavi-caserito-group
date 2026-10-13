<?php

declare(strict_types=1);

it('traslada stock entre almacenes con el costo promedio del origen', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $branch = $this->withToken($token)->getJson('/api/v1/warehouses')->json('data.0.branch_id');
    $second = $this->withToken($token)->postJson('/api/v1/warehouses', [
        'branch_id' => $branch, 'name' => 'Depósito',
    ])->assertCreated()->json('data.id');

    $this->withToken($token)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $f['warehouse'], 'to_warehouse_id' => $second,
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4]],
    ])->assertCreated();

    $stock = collect($this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data'));
    expect((float) $stock->firstWhere('warehouse_id', $f['warehouse'])['on_hand'])->toBe(6.0)
        ->and((float) $stock->firstWhere('warehouse_id', $second)['on_hand'])->toBe(4.0);
    $this->withToken($token)->getJson('/api/v1/inventory/transfers')->assertJsonCount(1, 'data');
});

it('no traslada mas de lo que hay ni al mismo almacen', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $branch = $this->withToken($token)->getJson('/api/v1/warehouses')->json('data.0.branch_id');
    $second = $this->withToken($token)->postJson('/api/v1/warehouses', [
        'branch_id' => $branch, 'name' => 'Depósito',
    ])->json('data.id');

    $this->withToken($token)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $f['warehouse'], 'to_warehouse_id' => $second,
        'lines' => [['variant_id' => $f['variant'], 'qty' => 99]],
    ])->assertStatus(422);
    $this->withToken($token)->postJson('/api/v1/inventory/transfers', [
        'from_warehouse_id' => $f['warehouse'], 'to_warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
    ])->assertStatus(422);
});
