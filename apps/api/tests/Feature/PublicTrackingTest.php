<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('muestra el seguimiento del pedido sin iniciar sesion y sin datos internos', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2, 'unit_price' => 118]],
    ])->assertCreated()->json('data');
    $publicToken = $order['public_token'];

    $view = $this->getJson("/api/v1/public/tracking/{$publicToken}")->assertOk()->json('data');

    expect($view['kind'])->toBe('order')->and($view['status_label'])->toBe('Pedido confirmado')
        ->and($view['lines'])->toHaveCount(1)->and($view)->not->toHaveKeys(['id', 'tenant_id', 'party_id']);
});

it('un token inexistente responde 404', function (): void {
    $this->getJson('/api/v1/public/tracking/'.str_repeat('x', 32))->assertStatus(404);
});

it('el token de un envio muestra su linea de tiempo', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $carrier = $this->withToken($token)->postJson('/api/v1/carriers', ['name' => 'Olva', 'kind' => 'agency'])
        ->assertCreated()->json('data.id');
    $shipment = $this->withToken($token)->postJson('/api/v1/shipments', [
        'carrier_id' => $carrier, 'recipient_name' => 'Ana', 'destination_office' => 'Olva Arequipa',
    ])->assertCreated()->json('data');
    $this->withToken($token)->postJson("/api/v1/shipments/{$shipment['id']}/status", ['status' => 'dispatched'])->assertOk();

    $publicToken = TenantContext::asProvider(fn () => DB::table('ship.shipments')->where('id', $shipment['id'])->value('public_token'));
    $view = $this->getJson("/api/v1/public/tracking/{$publicToken}")->assertOk()->json('data');

    expect($view['kind'])->toBe('shipment')->and($view['carrier'])->toBe('Olva')
        ->and($view['events'])->toHaveCount(2)->and($view['status_label'])->toBe('Despachado');
});

it('el seguimiento publico lleva la marca configurada del negocio', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $this->withToken($token)->putJson('/api/v1/branding', [
        'brand_color' => '#ff6600', 'brand_tagline' => 'Juguetes con cariño',
    ])->assertOk();
    $this->withToken($token)->putJson('/api/v1/branding', ['brand_color' => 'rojo'])->assertStatus(422);
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1, 'unit_price' => 118]],
    ])->assertCreated()->json('data');

    $view = $this->getJson("/api/v1/public/tracking/{$order['public_token']}")->assertOk()->json('data');

    expect($view['brand'])->toBe(['color' => '#ff6600', 'tagline' => 'Juguetes con cariño']);
});
