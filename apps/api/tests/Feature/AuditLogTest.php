<?php

declare(strict_types=1);

it('lista los cambios de la cuenta con quien los hizo', function (): void {
    $token = ownerToken();
    $this->withToken($token)->postJson('/api/v1/brands', ['name' => 'Gavi'])->assertCreated();

    $log = $this->withToken($token)->getJson('/api/v1/audit-log?entity_type=brands')->assertOk()->json('data');

    expect($log)->not->toBeEmpty()->and($log[0]['action'])->toBe('created')
        ->and($log[0]['actor_name'])->not->toBeNull();
});

it('un usuario sin permiso no ve la auditoria', function (): void {
    $token = ownerToken();
    $roles = $this->withToken($token)->getJson('/api/v1/roles')->json('data');
    $cashier = collect($roles)->firstWhere('name', 'Cajero');
    $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Rosa Cajera', 'email' => 'rosa@demo.test', 'password' => 'clave-larga-123',
        'role_ids' => [$cashier['id']],
    ])->assertCreated();

    $this->withToken(loginToken('rosa@demo.test', 'clave-larga-123'))->getJson('/api/v1/audit-log')->assertForbidden();
});
