<?php

declare(strict_types=1);

it('administra el catalogo de atributos con valores sugeridos', function (): void {
    $token = ownerToken();

    $id = $this->withToken($token)->postJson('/api/v1/attributes', [
        'name' => 'Talla', 'suggested_values' => 'S, M, L',
    ])->assertCreated()->json('data.id');

    $this->withToken($token)->postJson('/api/v1/attributes', ['name' => 'talla'])->assertStatus(422);
    $this->withToken($token)->getJson('/api/v1/attributes')->assertJsonPath('data.0.suggested_values', 'S, M, L');
    $this->withToken($token)->deleteJson("/api/v1/attributes/{$id}")->assertSuccessful();
});
