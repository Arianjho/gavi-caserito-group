<?php

declare(strict_types=1);

it('crea un usuario con perfil y entra con sus credenciales', function (): void {
    $token = ownerToken();
    $roles = $this->withToken($token)->getJson('/api/v1/roles')->json('data');
    $seller = collect($roles)->firstWhere('name', 'Vendedor');

    $user = $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Marta Vendedora', 'email' => 'marta@demo.test', 'password' => 'clave-larga-123',
        'role_ids' => [$seller['id']],
    ])->assertCreated()->json('data');

    expect($user['roles'])->toHaveCount(1)->and($user)->not->toHaveKey('password');

    expect(loginToken('marta@demo.test', 'clave-larga-123'))->toBeString()->not->toBeEmpty();
});

it('desactivar un usuario le cierra el acceso y el propietario esta protegido', function (): void {
    $token = ownerToken();
    $roles = $this->withToken($token)->getJson('/api/v1/roles')->json('data');
    $role = collect($roles)->first();

    $user = $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Pedro Cajero', 'email' => 'pedro@demo.test', 'password' => 'clave-larga-123',
        'role_ids' => [$role['id']],
    ])->json('data');

    $this->withToken($token)->patchJson("/api/v1/users/{$user['id']}/active", ['active' => false])
        ->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson('/api/v1/auth/login', ['email' => 'pedro@demo.test', 'password' => 'clave-larga-123'])
        ->assertStatus(401);

    $users = $this->withToken($token)->getJson('/api/v1/users')->json('data');
    $owner = collect($users)->firstWhere('is_owner', true);
    $this->withToken($token)->patchJson("/api/v1/users/{$owner['id']}/active", ['active' => false])
        ->assertStatus(422)->assertJsonPath('code', 'owner_protected');
});

it('un usuario sin permiso no puede administrar usuarios', function (): void {
    $token = ownerToken();
    $roles = $this->withToken($token)->getJson('/api/v1/roles')->json('data');
    $warehouse = collect($roles)->firstWhere('name', 'Almacén');
    $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Luis Almacén', 'email' => 'luis@demo.test', 'password' => 'clave-larga-123',
        'role_ids' => [$warehouse['id']],
    ])->assertCreated();

    $login = loginToken('luis@demo.test', 'clave-larga-123');

    $this->withToken($login)->getJson('/api/v1/users')->assertForbidden();
});
