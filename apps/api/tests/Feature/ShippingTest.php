<?php

declare(strict_types=1);

it('gestiona agencias y no elimina una con envios', function (): void {
    $token = ownerToken();
    saleFixture($token);
    $carrier = $this->withToken($token)->postJson('/api/v1/carriers', ['name' => 'Shalom', 'kind' => 'agency'])
        ->assertCreated()->json('data.id');

    $this->withToken($token)->postJson('/api/v1/shipments', [
        'carrier_id' => $carrier, 'recipient_name' => 'Rosa Quispe', 'recipient_phone' => '999888777',
        'destination_office' => 'Shalom Arequipa', 'freight_amount' => 12.5,
    ])->assertCreated();

    $this->withToken($token)->deleteJson("/api/v1/carriers/{$carrier}")
        ->assertStatus(409)->assertJsonPath('code', 'record_in_use');
});

it('un envio avanza por estados y deja linea de tiempo', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $carrier = $this->withToken($token)->postJson('/api/v1/carriers', ['name' => 'Olva'])->json('data.id');
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1, 'unit_price' => 118]],
    ])->json('data');

    $shipment = $this->withToken($token)->postJson('/api/v1/shipments', [
        'order_id' => $order['id'], 'carrier_id' => $carrier, 'recipient_name' => 'Boutique Ana',
        'cod_amount' => 118, 'freight_status' => 'collect_on_delivery',
    ])->assertCreated()->json('data');

    expect($shipment['status'])->toBe('pending')->and($shipment['number'])->toBe(1)
        ->and($shipment['events'])->toHaveCount(1);

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipment['id']}/status", ['status' => 'dispatched'])
        ->assertOk()->assertJsonPath('data.status', 'dispatched');
    $done = $this->withToken($token)->postJson("/api/v1/shipments/{$shipment['id']}/status", [
        'status' => 'delivered', 'note' => 'Recibido por la clienta',
    ])->assertOk()->json('data');

    expect($done['events'])->toHaveCount(3)->and($done['delivered_at'])->not->toBeNull();

    $this->withToken($token)->postJson("/api/v1/shipments/{$shipment['id']}/status", ['status' => 'in_transit'])
        ->assertStatus(409)->assertJsonPath('code', 'shipment_closed');
});
