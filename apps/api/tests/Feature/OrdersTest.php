<?php

declare(strict_types=1);

/**
 * Prepara cliente, almacén, producto con precio 118 y 10 unidades.
 *
 * @param string $token Token del propietario.
 * @return array{warehouse: string, variant: string, party: string}
 */
function orderFixture(string $token): array
{
    $f = saleFixture($token);
    $party = test()->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Boutique Ana'])
        ->json('data.id');

    return $f + ['party' => $party];
}

it('crea un pedido, reserva stock y registra el anticipo', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);

    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4, 'unit_price' => 118]],
        'prepaid' => ['method' => 'yape', 'amount' => 200],
    ])->assertCreated()->json('data');

    expect($order['status'])->toBe('confirmed')
        ->and($order['total'])->toBe('472.00')
        ->and($order['prepaid_total'])->toBe('200.00')
        ->and($order['number'])->toBe(1);

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(10.0)->and((float) $stock['reserved'])->toBe(4.0);
});

it('no reserva mas de lo disponible', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);

    $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 11, 'unit_price' => 118]],
    ])->assertStatus(422)->assertJsonPath('code', 'stock_insufficient');

    $this->withToken($token)->getJson('/api/v1/orders')->assertJsonCount(0, 'data');
});

it('entrega parcial aplica el anticipo y luego completa el pedido', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);

    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4, 'unit_price' => 118]],
        'prepaid' => ['method' => 'yape', 'amount' => 200],
    ])->json('data');
    $lineId = $order['lines'][0]->id ?? $order['lines'][0]['id'];

    // Primera entrega: 2 unidades = 236; el anticipo cubre 200, se cobra 36.
    $first = $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/deliver", [
        'deliveries' => [['line_id' => $lineId, 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 36]],
    ])->assertOk()->json('data');

    expect($first['status'])->toBe('confirmed')
        ->and($first['sales'])->toHaveCount(1)
        ->and($first['prepaid_applied'])->toBe('200.00');

    // Segunda entrega: 2 unidades = 236; ya no hay anticipo.
    $second = $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/deliver", [
        'deliveries' => [['line_id' => $lineId, 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 236]],
    ])->assertOk()->json('data');

    expect($second['status'])->toBe('delivered')->and($second['sales'])->toHaveCount(2);

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(6.0)->and((float) $stock['reserved'])->toBe(0.0);
});

it('rechaza entregar mas de lo pendiente', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2, 'unit_price' => 118]],
    ])->json('data');
    $lineId = $order['lines'][0]['id'];

    $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/deliver", [
        'deliveries' => [['line_id' => $lineId, 'qty' => 3]],
        'payments' => [['method' => 'cash', 'amount' => 354]],
    ])->assertStatus(422)->assertJsonPath('code', 'qty_exceeds_pending');
});

it('cancelar libera la reserva y el estado avanza en orden', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $id = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 5, 'unit_price' => 118]],
    ])->json('data.id');

    $this->withToken($token)->postJson("/api/v1/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', 'in_dispatch');
    $this->withToken($token)->postJson("/api/v1/orders/{$id}/advance")->assertOk()->assertJsonPath('data.status', 'ready');
    $this->withToken($token)->postJson("/api/v1/orders/{$id}/advance")->assertStatus(409);

    $this->withToken($token)->postJson("/api/v1/orders/{$id}/cancel", ['reason' => 'Cliente desistió'])
        ->assertOk()->assertJsonPath('data.status', 'cancelled');

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['reserved'])->toBe(0.0);
});

it('edita un pedido sin entregas y reajusta la reserva', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4, 'unit_price' => 118]],
        'prepaid' => ['method' => 'yape', 'amount' => 100],
    ])->assertCreated()->json('data');

    $updated = $this->withToken($token)->putJson("/api/v1/orders/{$order['id']}", [
        'delivery_type' => 'shipment', 'notes' => 'Entregar el viernes',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2, 'unit_price' => 118]],
    ])->assertOk()->json('data');

    expect($updated['total'])->toBe('236.00')->and($updated['delivery_type'])->toBe('shipment');
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['reserved'])->toBe(2.0);

    $this->withToken($token)->putJson("/api/v1/orders/{$order['id']}", [
        'delivery_type' => 'pickup',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 0.5, 'unit_price' => 100]],
    ])->assertStatus(422)->assertJsonPath('code', 'prepaid_exceeds_total');
});

it('devuelve el anticipo de un pedido cancelado, sin pasarse de lo disponible', function (): void {
    $token = ownerToken();
    $f = orderFixture($token);
    $order = $this->withToken($token)->postJson('/api/v1/orders', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $f['party'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1, 'unit_price' => 118]],
        'prepaid' => ['method' => 'yape', 'amount' => 100],
    ])->assertCreated()->json('data');

    $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/refund", ['method' => 'yape'])
        ->assertStatus(409)->assertJsonPath('code', 'order_not_cancelled');
    $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/cancel", ['reason' => 'Cliente desistió'])->assertOk();

    $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/refund", ['method' => 'yape', 'amount' => 150])
        ->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_prepaid');
    $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/refund", ['method' => 'yape', 'amount' => 40])
        ->assertCreated();
    $done = $this->withToken($token)->postJson("/api/v1/orders/{$order['id']}/refund", ['method' => 'yape'])
        ->assertCreated()->json('data');

    expect($done['refunds'])->toHaveCount(2)->and($done['refunds'][1]['amount'])->toBe('60.00');
});
