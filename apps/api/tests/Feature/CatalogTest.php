<?php

declare(strict_types=1);

use App\Modules\Platform\Application\ProvisionAccount;

it('crea un producto con variante predeterminada y codigo de barras', function (): void {
    $token = ownerToken();

    $item = $this->withToken($token)->postJson('/api/v1/items', [
        'name' => 'Carro de juguete', 'sku' => 'CAR-001', 'barcode' => '7750000000017', 'cost_reference' => 8.5,
    ])->assertCreated()->json('data');

    expect($item['variants'])->toHaveCount(1)
        ->and($item['variants'][0]['is_default'])->toBeTrue()
        ->and($item['variants'][0]['barcodes'])->toBe(['7750000000017'])
        ->and($item['base_uom'])->toBe('NIU')
        ->and($item['tracks_stock'])->toBeTrue();
});

it('un servicio usa ZZ y no controla stock', function (): void {
    $token = ownerToken();

    $item = $this->withToken($token)->postJson('/api/v1/items', [
        'name' => 'Envoltura de regalo', 'kind' => 'service', 'tracks_stock' => true,
    ])->assertCreated()->json('data');

    expect($item['base_uom'])->toBe('ZZ')->and($item['tracks_stock'])->toBeFalse();
});

it('busca por codigo de barras y por nombre', function (): void {
    $token = ownerToken();
    $this->withToken($token)->postJson('/api/v1/items', [
        'name' => 'Muñeca articulada', 'sku' => 'MUN-9', 'barcode' => '7750000000024',
    ])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/items/lookup?code=7750000000024')
        ->assertOk()->assertJsonPath('data.name', 'Muñeca articulada');
    $this->withToken($token)->getJson('/api/v1/items/lookup?code=000')->assertStatus(404);
    $this->withToken($token)->getJson('/api/v1/items?q=mun')->assertJsonCount(1, 'data');
});

it('rechaza codigo de barras o SKU duplicado', function (): void {
    $token = ownerToken();
    $this->withToken($token)->postJson('/api/v1/items', ['name' => 'A', 'sku' => 'S1', 'barcode' => '111'])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/items', ['name' => 'B', 'sku' => 'S1'])
        ->assertStatus(422)->assertJsonValidationErrors('sku');
    $this->withToken($token)->postJson('/api/v1/items', ['name' => 'B', 'barcode' => '111'])
        ->assertStatus(422)->assertJsonValidationErrors('barcode');
});

it('actualiza y desactiva un producto', function (): void {
    $token = ownerToken();
    $id = $this->withToken($token)->postJson('/api/v1/items', ['name' => 'A', 'barcode' => '222'])->json('data.id');

    $this->withToken($token)->putJson("/api/v1/items/{$id}", ['name' => 'A2', 'barcode' => '333', 'sku' => 'N1'])
        ->assertOk()->assertJsonPath('data.name', 'A2')->assertJsonPath('data.variants.0.barcodes.0', '333');

    $this->withToken($token)->patchJson("/api/v1/items/{$id}/active", ['active' => false])
        ->assertOk()->assertJsonPath('data.status', 'inactive');
});

it('una categoria con productos no se elimina', function (): void {
    $token = ownerToken();
    $cat = $this->withToken($token)->postJson('/api/v1/categories', ['name' => 'Juguetes'])->assertCreated()->json('data.id');
    $this->withToken($token)->postJson('/api/v1/items', ['name' => 'A', 'category_id' => $cat])->assertCreated();

    $this->withToken($token)->deleteJson("/api/v1/categories/{$cat}")
        ->assertStatus(409)->assertJsonPath('can_deactivate', true);
});

it('aisla el catalogo entre cuentas', function (): void {
    $a = ownerToken();
    $this->withToken($a)->postJson('/api/v1/items', ['name' => 'Solo de A', 'barcode' => '999'])->assertCreated();

    app(ProvisionAccount::class)
        ->handle('Otra', 'Otro', 'otro@example.test', 'clave-12345');
    $b = loginToken('otro@example.test', 'clave-12345');

    $this->withToken($b)->getJson('/api/v1/items')->assertJsonCount(0, 'data');
    $this->withToken($b)->getJson('/api/v1/items/lookup?code=999')->assertStatus(404);
    // El mismo código de barras puede existir en otra cuenta.
    $this->withToken($b)->postJson('/api/v1/items', ['name' => 'Mío', 'barcode' => '999'])->assertCreated();
});
