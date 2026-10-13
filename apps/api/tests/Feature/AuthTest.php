<?php

declare(strict_types=1);

it('inicia sesion y devuelve token', function (): void {
    accountWithUser();

    $this->postJson('/api/v1/auth/login', ['email' => 'ANA@example.test', 'password' => 'secreto-123'])
        ->assertOk()
        ->assertJsonStructure(['token', 'user' => ['id', 'tenant_id', 'email']]);
});

it('rechaza credenciales invalidas con el mismo mensaje', function (): void {
    accountWithUser();

    $this->postJson('/api/v1/auth/login', ['email' => 'ana@example.test', 'password' => 'mala'])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    $this->postJson('/api/v1/auth/login', ['email' => 'nadie@example.test', 'password' => 'x'])
        ->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
});

it('autentica con el token y exige token', function (): void {
    accountWithUser();
    accountWithUser('luis@example.test');

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.test', 'password' => 'secreto-123',
    ])->json('token');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('user.email', 'ana@example.test');

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $this->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('revoca el token al cerrar sesion', function (): void {
    accountWithUser();
    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.test', 'password' => 'secreto-123',
    ])->json('token');

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});
