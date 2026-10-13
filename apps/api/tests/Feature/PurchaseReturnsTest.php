<?php

declare(strict_types=1);

it('devuelve mercaderia al proveedor: baja stock y reduce lo que se debe', function (): void {
    $token = ownerToken();
    $f = purchaseFixture($token);
    $purchase = $this->withToken($token)->postJson('/api/v1/purchases', [
        'warehouse_id' => $f['warehouse'], 'supplier_id' => $f['supplier'], 'issued_date' => now()->toDateString(),
        'lines' => [['variant_id' => $f['variant'], 'qty' => 5, 'unit_cost' => 10]],
        'on_credit' => true, 'due_date' => now()->addDays(15)->toDateString(),
    ])->assertCreated()->json('data');
    $lineId = $purchase['lines'][0]['id'];

    $return = $this->withToken($token)->postJson("/api/v1/purchases/{$purchase['id']}/returns", [
        'lines' => [['line_id' => $lineId, 'qty' => 2]], 'reason' => 'Falla de fábrica',
    ])->assertCreated()->json('data');

    expect($return['total'])->toBe('20.00');
    $this->withToken($token)->postJson("/api/v1/purchases/{$purchase['id']}/returns", [
        'lines' => [['line_id' => $lineId, 'qty' => 4]], 'reason' => 'x',
    ])->assertStatus(422)->assertJsonPath('code', 'qty_exceeds_bought');
    $payables = $this->withToken($token)->getJson('/api/v1/payables')->json('data');
    expect((float) $payables[0]['balance'])->toBe(30.0);
});
