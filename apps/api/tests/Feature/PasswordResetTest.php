<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Crea un usuario y devuelve el token plano de recuperación leído del correo.
 *
 * @param string $token Token del propietario.
 * @return string Token de restablecimiento.
 */
function requestResetToken(string $token): string
{
    $roles = test()->withToken($token)->getJson('/api/v1/roles')->json('data');
    test()->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Lucía Vendedora', 'email' => 'lucia@demo.test', 'password' => 'clave-larga-123',
        'role_ids' => [$roles[0]['id']],
    ])->assertCreated();

    $captured = '';
    Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $text) use (&$captured): void {
        $captured = $text;
    });
    test()->postJson('/api/v1/auth/forgot-password', ['email' => 'lucia@demo.test'])->assertStatus(202);
    preg_match('/token=([A-Za-z0-9]{64})/', $captured, $match);

    return $match[1] ?? '';
}

it('restablece la contraseña con el enlace y cierra las sesiones', function (): void {
    $owner = ownerToken();
    $reset = requestResetToken($owner);
    expect($reset)->toHaveLength(64);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'lucia@demo.test', 'token' => $reset, 'password' => 'otra-clave-segura-1',
    ])->assertOk();

    expect(loginToken('lucia@demo.test', 'otra-clave-segura-1'))->toBeString();
    $this->postJson('/api/v1/auth/login', ['email' => 'lucia@demo.test', 'password' => 'clave-larga-123'])
        ->assertStatus(401);
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'lucia@demo.test', 'token' => $reset, 'password' => 'otra-clave-segura-2',
    ])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
});

it('no revela si el correo existe y rechaza tokens vencidos', function (): void {
    $owner = ownerToken();
    $reset = requestResetToken($owner);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nadie@demo.test'])->assertStatus(202);

    DB::table('core.password_resets')->update(['expires_at' => now()->subMinute()]);
    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'lucia@demo.test', 'token' => $reset, 'password' => 'otra-clave-segura-1',
    ])->assertStatus(422);
});
