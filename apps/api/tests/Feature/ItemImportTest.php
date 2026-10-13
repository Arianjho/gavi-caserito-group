<?php

declare(strict_types=1);

it('valida sin escribir cuando es dry_run', function (): void {
    $token = ownerToken();

    $result = $this->withToken($token)->postJson('/api/v1/import/items', [
        'dry_run' => true,
        'rows' => [['name' => 'Polo básico', 'sku' => 'POLO-1', 'price' => 29.9], ['name' => '']],
    ])->assertOk()->json('data');

    expect($result['created'])->toBe(0)->and($result['failed'])->toBe(1)
        ->and($result['rows'][0]['status'])->toBe('valid')->and($result['rows'][1]['status'])->toBe('error');
    $this->withToken($token)->getJson('/api/v1/items')->assertJsonCount(0, 'data');
});

it('importa productos con categoria, marca y stock inicial y reporta duplicados', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $result = $this->withToken($token)->postJson('/api/v1/import/items', [
        'warehouse_id' => $f['warehouse'],
        'rows' => [
            ['name' => 'Polo básico', 'sku' => 'POLO-1', 'barcode' => '7750000000011', 'category' => 'Ropa', 'brand' => 'Gavi', 'cost' => 10, 'price' => 29.9, 'stock' => 5],
            ['name' => 'Short', 'sku' => 'POLO-1'],
            ['name' => 'Gorra', 'category' => 'ropa', 'price' => 15],
        ],
    ])->assertOk()->json('data');

    expect($result['created'])->toBe(2)->and($result['failed'])->toBe(1)
        ->and($result['rows'][1]['errors'][0])->toContain('POLO-1');

    $this->withToken($token)->getJson('/api/v1/items/lookup?code=7750000000011')->assertOk();
    $categories = $this->withToken($token)->getJson('/api/v1/categories')->json('data');
    expect(collect($categories)->where('name', 'Ropa'))->toHaveCount(1);
});

it('pide almacen cuando una fila trae stock', function (): void {
    $token = ownerToken();

    $result = $this->withToken($token)->postJson('/api/v1/import/items', [
        'rows' => [['name' => 'Polo', 'stock' => 3]],
    ])->assertOk()->json('data');

    expect($result['failed'])->toBe(1)->and($result['rows'][0]['errors'][0])->toContain('almacén');
});
