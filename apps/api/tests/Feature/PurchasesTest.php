<?php

declare(strict_types=1);

/**
 * Prepara producto con stock inicial, almacén y un proveedor.
 *
 * @param string $token Token del propietario.
 * @return array{warehouse: string, variant: string, supplier: string}
 */
function purchaseFixture(string $token): array
{
    $f = saleFixture($token);
    $supplier = test()->withToken($token)->postJson('/api/v1/suppliers', [
        'doc_type' => 'RUC', 'doc_number' => '20100070970', 'name' => 'Distribuidora Norte SAC',
    ])->assertCreated()->json('data.id');

    return $f + ['supplier' => $supplier];
}

it('registra proveedores aparte de los clientes', function (): void {
    $token = ownerToken();
    $f = purchaseFixture($token);

    $this->withToken($token)->getJson('/api/v1/suppliers')->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_supplier', true);
    $this->withToken($token)->getJson('/api/v1/customers')->assertJsonMissing(['id' => $f['supplier']]);
});

it('una compra al contado ingresa stock y queda pagada', function (): void {
    $token = ownerToken();
    $f = purchaseFixture($token);

    $doc = $this->withToken($token)->postJson('/api/v1/purchases', [
        'warehouse_id' => $f['warehouse'], 'supplier_id' => $f['supplier'], 'issued_date' => '2026-10-12',
        'supplier_document' => 'F001-123', 'method' => 'transfer',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 5, 'unit_cost' => 40]],
    ])->assertCreated()->json('data');

    expect($doc['total'])->toBe('200.00')->and($doc['paid_total'])->toBe('200.00')->and($doc['status'])->toBe('confirmed');

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(15.0);
});

it('una compra a credito se paga por partes y sale de por pagar', function (): void {
    $token = ownerToken();
    $f = purchaseFixture($token);
    $id = $this->withToken($token)->postJson('/api/v1/purchases', [
        'warehouse_id' => $f['warehouse'], 'supplier_id' => $f['supplier'], 'issued_date' => '2026-10-12',
        'on_credit' => true, 'due_date' => '2026-11-12',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 10, 'unit_cost' => 30]],
    ])->assertCreated()->json('data.id');

    $payables = $this->withToken($token)->getJson('/api/v1/payables')->assertOk();
    expect($payables->json('summary.balance_total'))->toBe('300.00');

    $this->withToken($token)->postJson("/api/v1/purchases/{$id}/payments", ['method' => 'cash', 'amount' => 100])->assertOk();
    $this->withToken($token)->postJson("/api/v1/purchases/{$id}/payments", ['method' => 'cash', 'amount' => 250])
        ->assertStatus(422)->assertJsonPath('code', 'amount_exceeds_balance');
    $this->withToken($token)->postJson("/api/v1/purchases/{$id}/payments", ['method' => 'cash', 'amount' => 200])->assertOk();

    $this->withToken($token)->getJson('/api/v1/payables')->assertJsonCount(0, 'data');
});

it('anular una compra sin pagos revierte el stock', function (): void {
    $token = ownerToken();
    $f = purchaseFixture($token);
    $id = $this->withToken($token)->postJson('/api/v1/purchases', [
        'warehouse_id' => $f['warehouse'], 'supplier_id' => $f['supplier'], 'issued_date' => '2026-10-12',
        'on_credit' => true, 'due_date' => '2026-11-12',
        'lines' => [['variant_id' => $f['variant'], 'qty' => 4, 'unit_cost' => 30]],
    ])->json('data.id');

    $this->withToken($token)->postJson("/api/v1/purchases/{$id}/void", ['reason' => 'Error de carga'])
        ->assertOk()->assertJsonPath('data.status', 'voided');

    $stock = $this->withToken($token)->getJson('/api/v1/inventory/stock')->json('data.0');
    expect((float) $stock['on_hand'])->toBe(10.0);
});
