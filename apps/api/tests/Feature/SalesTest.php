<?php

declare(strict_types=1);

/**
 * Prepara almacén, producto con precio y stock inicial.
 *
 * @param string $token Token del propietario.
 * @param int $stock Existencias iniciales.
 * @return array{warehouse: string, variant: string}
 */
function saleFixture(string $token, int $stock = 10): array
{
    $f = stockFixture($token);

    test()->withToken($token)->putJson('/api/v1/items/'.test()->withToken($token)->getJson('/api/v1/items')->json('data.0.id'), [
        'name' => 'Carro', 'sale_price' => 118, 'cost_reference' => 60,
    ])->assertOk();

    test()->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'opening',
        'lines' => [['variant_id' => $f['variant'], 'qty' => $stock, 'unit_cost' => 60]],
    ])->assertCreated();

    return $f;
}

it('registra una venta con IGV, correlativo y descuenta stock', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $sale = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 236]],
    ])->assertCreated()->json('data');

    expect($sale['series'])->toBe('N001')
        ->and($sale['number'])->toBe(1)
        ->and($sale['total'])->toBe('236.00')
        ->and($sale['igv_total'])->toBe('36.00')
        ->and($sale['taxable_total'])->toBe('200.00')
        ->and($sale['lines'])->toHaveCount(1);

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(8.0);

    $second = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'yape', 'amount' => 118]],
    ])->assertCreated()->json('data');
    expect($second['number'])->toBe(2);
});

it('rechaza una venta con cobro que no cuadra', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 100]],
    ])->assertStatus(422)->assertJsonPath('code', 'payment_mismatch');
});

it('no vende mas de lo que hay y no deja nada a medias', function (): void {
    $token = ownerToken();
    $f = saleFixture($token, 1);

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 236]],
    ])->assertStatus(422)->assertJsonPath('code', 'stock_insufficient');

    $this->withToken($token)->getJson('/api/v1/sales')->assertJsonCount(0, 'data');
    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(1.0);
});

it('anula una venta y devuelve el stock', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $id = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 3]],
        'payments' => [['method' => 'cash', 'amount' => 354]],
    ])->json('data.id');

    $this->withToken($token)->postJson("/api/v1/sales/{$id}/void", ['reason' => 'Error de digitación'])
        ->assertOk()->assertJsonPath('data.status', 'voided');

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(10.0);

    $this->withToken($token)->postJson("/api/v1/sales/{$id}/void", ['reason' => 'Otra vez'])->assertStatus(409);
});

it('un producto sin precio no se puede vender', function (): void {
    $token = ownerToken();
    $f = stockFixture($token);
    $this->withToken($token)->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $f['warehouse'], 'type' => 'opening',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 5, 'unit_cost' => 1]],
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 10]],
    ])->assertStatus(422)->assertJsonPath('code', 'price_missing');
});

it('vende a credito y cobra por partes hasta saldar', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $party = $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Tienda Lola'])
        ->json('data.id');

    $id = $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $party, 'on_credit' => true,
        'due_date' => now()->addDays(15)->toDateString(),
        'lines' => [['variant_id' => $f['variant'], 'qty' => 2]],
        'payments' => [['method' => 'cash', 'amount' => 36]],
    ])->assertCreated()->assertJsonPath('data.payment_condition', 'credit')->json('data.id');

    $receivables = $this->withToken($token)->getJson('/api/v1/receivables')->assertOk();
    expect((float) $receivables->json('data.0.balance'))->toBe(200.0)
        ->and((float) $receivables->json('summary.balance_total'))->toBe(200.0);

    $this->withToken($token)->postJson("/api/v1/sales/{$id}/payments", ['method' => 'yape', 'amount' => 300])
        ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_balance');

    $this->withToken($token)->postJson("/api/v1/sales/{$id}/payments", ['method' => 'yape', 'amount' => 150])
        ->assertCreated()->assertJsonPath('data.balance', '50.00');
    $this->withToken($token)->postJson("/api/v1/sales/{$id}/payments", ['method' => 'cash', 'amount' => 50])
        ->assertCreated()->assertJsonPath('data.balance', '0.00');

    $this->withToken($token)->getJson('/api/v1/receivables')->assertJsonCount(0, 'data');
});

it('una venta a credito exige cliente y un contado no admite cobro parcial', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'on_credit' => true, 'due_date' => now()->addDay()->toDateString(),
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]], 'payments' => [],
    ])->assertStatus(422)->assertJsonPath('code', 'party_required');

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'],
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]], 'payments' => [],
    ])->assertStatus(422)->assertJsonPath('code', 'payment_mismatch');
});

it('reparte un cobro del cliente entre sus deudas, la que vence primero', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $party = $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Tienda Lola'])
        ->json('data.id');
    $ids = [];
    foreach ([20, 5] as $days) {
        $ids[$days] = $this->withToken($token)->postJson('/api/v1/sales', [
            'warehouse_id' => $f['warehouse'], 'party_id' => $party, 'on_credit' => true,
            'due_date' => now()->addDays($days)->toDateString(),
            'lines' => [['variant_id' => $f['variant'], 'qty' => 1]], 'payments' => [],
        ])->assertCreated()->json('data.id');
    }

    $applied = $this->withToken($token)->postJson('/api/v1/receivables/collect-party', [
        'party_id' => $party, 'method' => 'yape', 'amount' => 150,
    ])->assertCreated()->json('data.applied');

    expect($applied)->toHaveCount(2)
        ->and($applied[0]['document_id'])->toBe($ids[5])
        ->and($applied[0]['applied'])->toBe('118.00')
        ->and($applied[1]['applied'])->toBe('32.00');
    $this->withToken($token)->postJson('/api/v1/receivables/collect-party', [
        'party_id' => $party, 'method' => 'yape', 'amount' => 500,
    ])->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_balance');
});
