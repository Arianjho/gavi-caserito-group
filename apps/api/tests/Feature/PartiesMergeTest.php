<?php

declare(strict_types=1);

it('une un cliente duplicado: su historial pasa al que se conserva', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $keep = $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Lola Perez'])->json('data.id');
    $dup = $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Lola Pérez'])->json('data.id');
    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $dup,
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 118]],
    ])->assertCreated();

    $this->withToken($token)->postJson("/api/v1/customers/{$dup}/merge", ['into_id' => $dup])->assertStatus(422);
    $moved = $this->withToken($token)->postJson("/api/v1/customers/{$dup}/merge", ['into_id' => $keep])
        ->assertOk()->json('data.moved');

    expect($moved['sales.sales_documents'])->toBe(1);
    $names = collect($this->withToken($token)->getJson('/api/v1/customers')->json('data'))->pluck('id');
    expect($names->contains($dup))->toBeFalse()->and($names->contains($keep))->toBeTrue();
});
