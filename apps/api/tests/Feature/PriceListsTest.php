<?php

declare(strict_types=1);

it('resuelve el precio por lista y escala de cantidad para el cliente', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);

    $list = $this->withToken($token)->postJson('/api/v1/price-lists', ['name' => 'Mayorista'])
        ->assertCreated()->json('data');
    $this->withToken($token)->putJson("/api/v1/price-lists/{$list['id']}/items", ['items' => [
        ['variant_id' => $f['variant'], 'min_qty' => 1, 'price' => 100],
        ['variant_id' => $f['variant'], 'min_qty' => 10, 'price' => 90],
    ]])->assertOk()->assertJsonCount(2, 'data');

    $resolve = fn (?string $party, int $qty) => $this->withToken($token)->postJson('/api/v1/prices/resolve', [
        'party_id' => $party, 'lines' => [['variant_id' => $f['variant'], 'qty' => $qty]],
    ])->assertOk()->json('data.0');

    expect($resolve($f['party'], 3)['source'])->toBe('base');

    $this->withToken($token)->putJson("/api/v1/customers/{$f['party']}", [
        'doc_type' => 'NONE', 'name' => 'Boutique Ana', 'price_list_id' => $list['id'],
    ])->assertOk();

    $small = $resolve($f['party'], 3);
    $big = $resolve($f['party'], 12);
    expect($small['source'])->toBe('list')->and((float) $small['unit_price'])->toBe(100.0)
        ->and((float) $big['unit_price'])->toBe(90.0);
});

it('no elimina una lista asignada a un cliente', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $list = $this->withToken($token)->postJson('/api/v1/price-lists', ['name' => 'VIP'])->json('data');
    $this->withToken($token)->putJson("/api/v1/customers/{$f['party']}", [
        'doc_type' => 'NONE', 'name' => 'Boutique Ana', 'price_list_id' => $list['id'],
    ])->assertOk();

    $this->withToken($token)->deleteJson("/api/v1/price-lists/{$list['id']}")->assertStatus(409);
});

it('una venta de mostrador usa la lista de precios del cliente', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $list = $this->withToken($token)->postJson('/api/v1/price-lists', ['name' => 'Mayorista'])->json('data');
    $this->withToken($token)->putJson("/api/v1/price-lists/{$list['id']}/items", ['items' => [
        ['variant_id' => $f['variant'], 'min_qty' => 1, 'price' => 100],
    ]])->assertOk();
    $this->withToken($token)->putJson("/api/v1/customers/{$f['party']}", [
        'doc_type' => 'NONE', 'name' => 'Boutique Ana', 'price_list_id' => $list['id'],
    ])->assertOk();

    $sale = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'yape', 'amount' => 100]],
    ])->assertCreated()->json('data');

    expect($sale['total'])->toBe('100.00');
});
