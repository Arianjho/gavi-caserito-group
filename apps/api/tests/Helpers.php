<?php

declare(strict_types=1);

use App\Modules\Platform\Application\ProvisionAccount;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;

/**
 * Inicia sesión y devuelve el token.
 *
 * @param string $email Correo.
 * @param string $password Contraseña.
 */
function loginToken(string $email, string $password): string
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('token');
}

/**
 * Crea una cuenta con un usuario activo.
 *
 * @param string $email Correo del usuario.
 * @return array{0: Tenant, 1: User}
 */
function accountWithUser(string $email = 'ana@example.test'): array
{
    return TenantContext::asProvider(function () use ($email): array {
        $tenant = Tenant::create(['name' => 'Jugueteria Mi Mundo']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Ana',
            'email' => $email,
            'password' => 'secreto-123',
            'is_owner' => true,
        ]);

        return [$tenant, $user];
    });
}

/**
 * Crea una cuenta con propietario y devuelve el token de este.
 */
function ownerToken(): string
{
    app(ProvisionAccount::class)->handle('Cuenta Org', 'Dueña', 'org@example.test', 'clave-12345');

    return loginToken('org@example.test', 'clave-12345');
}
