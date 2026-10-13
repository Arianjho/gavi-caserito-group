<?php

declare(strict_types=1);

it('crea clientes validando DNI y RUC', function (): void {
    $token = ownerToken();

    $this->withToken($token)->postJson('/api/v1/customers', [
        'doc_type' => 'DNI', 'doc_number' => '1234', 'name' => 'Ana',
    ])->assertStatus(422)->assertJsonValidationErrors('doc_number');

    $this->withToken($token)->postJson('/api/v1/customers', [
        'doc_type' => 'DNI', 'doc_number' => '45678912', 'name' => 'Ana Pérez', 'phone' => '999888777',
    ])->assertCreated();

    $this->withToken($token)->postJson('/api/v1/customers', [
        'doc_type' => 'RUC', 'doc_number' => '20601234560', 'name' => 'Empresa',
    ])->assertStatus(422);

    $this->withToken($token)->postJson('/api/v1/customers', [
        'doc_type' => 'RUC', 'doc_number' => '20601234565', 'name' => 'Empresa SAC',
    ])->assertCreated();
});

it('no permite documentos duplicados y permite clientes sin documento', function (): void {
    $token = ownerToken();
    $payload = ['doc_type' => 'DNI', 'doc_number' => '45678912', 'name' => 'Ana'];

    $this->withToken($token)->postJson('/api/v1/customers', $payload)->assertCreated();
    $this->withToken($token)->postJson('/api/v1/customers', $payload)->assertStatus(422);

    $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Cliente varios'])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Otro sin doc'])->assertCreated();
});

it('busca por nombre o telefono', function (): void {
    $token = ownerToken();
    $this->withToken($token)->postJson('/api/v1/customers', [
        'doc_type' => 'NONE', 'name' => 'Rosa Quispe', 'phone' => '987654321',
    ])->assertCreated();

    $this->withToken($token)->getJson('/api/v1/customers?q=quispe')->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/customers?q=98765')->assertJsonCount(1, 'data');
    $this->withToken($token)->getJson('/api/v1/customers?q=zzz')->assertJsonCount(0, 'data');
});

it('un cliente con ventas no se elimina', function (): void {
    $token = ownerToken();
    $f = saleFixture($token);
    $party = $this->withToken($token)->postJson('/api/v1/customers', ['doc_type' => 'NONE', 'name' => 'Comprador'])
        ->json('data.id');

    $this->withToken($token)->postJson('/api/v1/sales', [
        'warehouse_id' => $f['warehouse'], 'party_id' => $party,
        'lines' => [['variant_id' => $f['variant'], 'qty' => 1]],
        'payments' => [['method' => 'cash', 'amount' => 118]],
    ])->assertCreated();

    $this->withToken($token)->deleteJson("/api/v1/customers/{$party}")
        ->assertStatus(409)->assertJsonPath('can_deactivate', true);
});
