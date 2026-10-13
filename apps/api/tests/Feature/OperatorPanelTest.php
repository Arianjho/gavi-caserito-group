<?php

declare(strict_types=1);

use App\Modules\Platform\Models\Operator;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Crea un operador y devuelve su token.
 */
function operatorToken(): string
{
    Operator::query()->create(['name' => 'Soporte', 'email' => 'soporte@gavi.test', 'password' => 'clave-operador-1']);

    return test()->postJson('/api/v1/operator/auth/login', [
        'email' => 'soporte@gavi.test', 'password' => 'clave-operador-1',
    ])->assertOk()->json('token');
}

/**
 * Prepara una petición con el token dado, descartando al usuario que el guard
 * de Sanctum dejó en memoria (en producción cada petición es un proceso nuevo).
 *
 * @param string $token Token de Sanctum.
 */
function asToken(string $token): TestCase
{
    app('auth')->forgetGuards();

    return test()->withToken($token);
}

it('un operador lista cuentas con su uso y las administra', function (): void {
    ownerToken();
    $operator = operatorToken();

    $list = asToken($operator)->getJson('/api/v1/operator/tenants')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['users_used'])->toBe(1);

    $id = $list[0]['id'];
    asToken($operator)->putJson("/api/v1/operator/tenants/{$id}/plan", [
        'plan_status' => 'active', 'plan_code' => 'pro', 'max_users' => 3,
    ])->assertOk()->assertJsonPath('data.max_users', 3);

    $log = TenantContext::asProvider(fn () => DB::table('core.audit_log')->where('action', 'operator_plan_updated')->count());
    expect($log)->toBe(1);
});

it('una cuenta suspendida no puede usar la API ni iniciar sesion', function (): void {
    $account = ownerToken();
    $operator = operatorToken();
    $id = asToken($operator)->getJson('/api/v1/operator/tenants')->json('data.0.id');

    asToken($operator)->putJson("/api/v1/operator/tenants/{$id}/plan", ['plan_status' => 'suspended'])->assertOk();

    asToken($account)->getJson('/api/v1/auth/me')->assertStatus(403)->assertJsonPath('code', 'account_suspended');
    $this->postJson('/api/v1/auth/login', ['email' => 'org@example.test', 'password' => 'clave-12345'])
        ->assertStatus(403)->assertJsonPath('code', 'account_suspended');

    asToken($operator)->putJson("/api/v1/operator/tenants/{$id}/plan", ['plan_status' => 'active'])->assertOk();
    asToken($account)->getJson('/api/v1/auth/me')->assertOk();
});

it('los tokens no se cruzan entre cuentas y operadores', function (): void {
    $account = ownerToken();
    $operator = operatorToken();

    asToken($account)->getJson('/api/v1/operator/tenants')->assertStatus(401);
    asToken($operator)->getJson('/api/v1/auth/me')->assertStatus(401);
    app('auth')->forgetGuards();
    $this->flushHeaders()->getJson('/api/v1/operator/tenants')->assertStatus(401);
});

it('un operador crea una cuenta nueva con su propietario', function (): void {
    $operator = operatorToken();

    $created = asToken($operator)->postJson('/api/v1/operator/tenants', [
        'name' => 'Bodega Luz', 'owner_name' => 'Luz', 'owner_email' => 'luz@bodega.test', 'owner_password' => 'clave-segura-123',
    ])->assertCreated()->json('data');

    expect($created['name'])->toBe('Bodega Luz')->and($created['owners'][0]['email'])->toBe('luz@bodega.test');
    $this->postJson('/api/v1/auth/login', ['email' => 'luz@bodega.test', 'password' => 'clave-segura-123'])->assertOk();
});
