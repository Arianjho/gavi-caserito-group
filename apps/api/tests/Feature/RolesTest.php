<?php

declare(strict_types=1);

use App\Modules\Platform\Application\PermissionChecker;
use App\Modules\Platform\Application\ProvisionAccount;
use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('crea perfiles base al aprovisionar una cuenta', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');

    $names = TenantContext::asProvider(
        fn () => Role::query()->where('tenant_id', $result['tenant']->id)->orderBy('name')->pluck('name')->all()
    );

    expect($names)->toBe(['Administrador', 'Almacén', 'Cajero', 'Vendedor']);
});

it('el propietario ve todos los permisos', function (): void {
    app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');
    $token = loginToken('x@example.test', 'clave-12345');

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonFragment(['settings.role.view']);
});

it('un usuario sin permiso recibe 403', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');

    TenantContext::asProvider(function () use ($result): void {
        User::create([
            'tenant_id' => $result['tenant']->id, 'name' => 'Vend', 'email' => 'v@example.test', 'password' => 'clave-12345',
        ]);
    });
    $token = loginToken('v@example.test', 'clave-12345');

    $this->withToken($token)->getJson('/api/v1/roles')
        ->assertStatus(403)->assertJsonPath('code', 'permission_denied');
});

it('un vendedor con perfil asignado tiene sus permisos y deny prevalece', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');

    $user = TenantContext::asProvider(function () use ($result): User {
        $user = User::create([
            'tenant_id' => $result['tenant']->id, 'name' => 'Vend', 'email' => 'v@example.test', 'password' => 'clave-12345',
        ]);
        $seller = Role::query()->where('tenant_id', $result['tenant']->id)->where('base_template', 'seller')->firstOrFail();
        DB::table('core.user_roles')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $result['tenant']->id,
            'user_id' => $user->id, 'role_id' => $seller->id,
        ]);
        DB::table('core.role_permissions')
            ->where('role_id', $seller->id)->where('permission_code', 'sales.pos.use')->update(['effect' => 'deny']);

        return $user;
    });

    $perms = TenantContext::asProvider(fn () => (new PermissionChecker)->effective($user));

    expect($perms)->toContain('orders.order.confirm')->not->toContain('sales.pos.use');
});

it('el propietario crea y elimina un perfil', function (): void {
    app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');
    $token = loginToken('x@example.test', 'clave-12345');

    $id = $this->withToken($token)->postJson('/api/v1/roles', [
        'name' => 'Supervisor', 'permissions' => ['reports.view', 'orders.order.view'],
    ])->assertCreated()->json('data.id');

    $this->withToken($token)->deleteJson("/api/v1/roles/{$id}")->assertOk();
    $this->withToken($token)->getJson('/api/v1/roles')->assertJsonMissing(['name' => 'Supervisor']);
});

it('no permite eliminar un perfil de sistema', function (): void {
    app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');
    $token = loginToken('x@example.test', 'clave-12345');

    $seller = collect($this->withToken($token)->getJson('/api/v1/roles')->json('data'))
        ->firstWhere('name', 'Vendedor');

    $this->withToken($token)->deleteJson('/api/v1/roles/'.$seller['id'])->assertStatus(422);
});

it('un perfil con usuarios devuelve 409 y ofrece desactivar', function (): void {
    $result = app(ProvisionAccount::class)->handle('Cuenta X', 'Dueña', 'x@example.test', 'clave-12345');
    $token = loginToken('x@example.test', 'clave-12345');

    $id = $this->withToken($token)->postJson('/api/v1/roles', ['name' => 'Supervisor'])->json('data.id');
    TenantContext::asProvider(function () use ($id, $result): void {
        DB::table('core.user_roles')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $result['tenant']->id,
            'user_id' => $result['owner']->id, 'role_id' => $id,
        ]);
    });

    $this->withToken($token)->deleteJson("/api/v1/roles/{$id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'record_in_use')
        ->assertJsonPath('can_deactivate', true);

    $this->withToken($token)->patchJson("/api/v1/roles/{$id}/active", ['active' => false])
        ->assertOk()->assertJsonPath('data.status', 'inactive');
});
