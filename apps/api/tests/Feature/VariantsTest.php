<?php

declare(strict_types=1);

it('genera combinaciones de talla y color reutilizando la variante predeterminada', function (): void {
    $token = ownerToken();
    $item = $this->withToken($token)->postJson('/api/v1/items', [
        'name' => 'Polo', 'sku' => 'POLO', 'sale_price' => 30, 'cost_reference' => 12,
    ])->assertCreated()->json('data');

    $result = $this->withToken($token)->postJson("/api/v1/items/{$item['id']}/variants/generate", [
        'axes' => [
            ['name' => 'Talla', 'values' => ['S', 'M']],
            ['name' => 'Color', 'values' => ['Rojo', 'Azul']],
        ],
    ])->assertCreated()->json('data');

    expect($result['created'])->toBe(4)->and($result['variants'])->toHaveCount(4);
    $names = collect($result['variants'])->pluck('name')->all();
    expect($names)->toContain('S / Rojo')->and($names)->toContain('M / Azul');
    $sample = collect($result['variants'])->firstWhere('name', 'M / Azul');
    expect($sample['sale_price'])->toBe('30.0000')->and($sample['attributes'])->toEqual(['Talla' => 'M', 'Color' => 'Azul']);

    $again = $this->withToken($token)->postJson("/api/v1/items/{$item['id']}/variants/generate", [
        'axes' => [['name' => 'Talla', 'values' => ['S']], ['name' => 'Color', 'values' => ['Rojo']]],
    ])->json('data');
    expect($again['created'])->toBe(0);

    $fresh = $this->withToken($token)->getJson('/api/v1/items?q=Polo')->json('data.0');
    expect($fresh['has_variants'])->toBeTrue()->and($fresh['variants'])->toHaveCount(4);
});

it('permite editar una variante y no desactivar la ultima', function (): void {
    $token = ownerToken();
    $item = $this->withToken($token)->postJson('/api/v1/items', ['name' => 'Gorra'])->json('data');
    $variantId = $item['variants'][0]['id'];

    $this->withToken($token)->patchJson("/api/v1/variants/{$variantId}/active", ['active' => false])
        ->assertStatus(422)->assertJsonPath('code', 'last_variant');

    $second = $this->withToken($token)->postJson("/api/v1/items/{$item['id']}/variants", [
        'name' => 'Negra', 'barcode' => '7750000000028', 'sale_price' => 20,
    ])->assertCreated()->json('data');

    $this->withToken($token)->putJson("/api/v1/variants/{$second['id']}", [
        'name' => 'Negra mate', 'sale_price' => 22, 'barcode' => '7750000000028',
    ])->assertOk()->assertJsonPath('data.name', 'Negra mate');
    $this->withToken($token)->patchJson("/api/v1/variants/{$variantId}/active", ['active' => false])
        ->assertOk()->assertJsonPath('data.status', 'inactive');

    $this->withToken($token)->getJson('/api/v1/items/lookup?code=7750000000028')->assertOk();
});
