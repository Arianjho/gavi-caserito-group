<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

it('muestra el plan con el uso actual', function (): void {
    $token = ownerToken();

    $data = $this->withToken($token)->getJson('/api/v1/plan')->assertOk()->json('data');

    expect($data['limits']['users']['used'])->toBe(1)->and($data['limits']['users']['max'])->toBeNull();
});

it('bloquea crear usuarios al llegar al tope del plan', function (): void {
    $token = ownerToken();
    TenantContext::asProvider(fn () => DB::table('core.tenants')->update(['max_users' => 1]));
    $role = $this->withToken($token)->getJson('/api/v1/roles')->json('data.0.id');

    $this->withToken($token)->postJson('/api/v1/users', [
        'name' => 'Cajera', 'email' => 'cajera@caserito.test', 'password' => 'clave-segura-123', 'role_ids' => [$role],
    ])->assertStatus(403)->assertJsonPath('code', 'plan_limit_reached');
});
