<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Models\Role;
use App\Modules\Platform\Models\RolePermission;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Crea una cuenta nueva con su usuario propietario y los perfiles base.
 *
 * Es el único punto de alta de cuentas: garantiza que toda cuenta nazca con
 * propietario y perfiles plantilla (administrador, vendedor, cajero, almacén).
 */
final class ProvisionAccount
{
    /**
     * Nombres visibles de las plantillas de perfil.
     *
     * @var array<string, string>
     */
    private const TEMPLATE_NAMES = [
        'administrator' => 'Administrador',
        'seller' => 'Vendedor',
        'cashier' => 'Cajero',
        'warehouse' => 'Almacén',
    ];

    /**
     * Crea la cuenta, el propietario y los perfiles base en una transacción.
     *
     * @param string $accountName Nombre comercial de la cuenta.
     * @param string $ownerName Nombre del propietario.
     * @param string $ownerEmail Correo de acceso del propietario.
     * @param string $ownerPassword Contraseña inicial del propietario.
     * @return array{tenant: Tenant, owner: User}
     */
    public function handle(
        string $accountName,
        string $ownerName,
        string $ownerEmail,
        string $ownerPassword,
    ): array {
        return TenantContext::asProvider(fn (): array => DB::transaction(function () use (
            $accountName, $ownerName, $ownerEmail, $ownerPassword,
        ): array {
            $tenant = Tenant::create([
                'name' => $accountName,
                'trial_ends_at' => now()->addDays(14),
            ]);

            $owner = User::create([
                'tenant_id' => $tenant->id,
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => $ownerPassword,
                'is_owner' => true,
            ]);

            foreach (PermissionCatalog::templates() as $template => $patterns) {
                $role = Role::create([
                    'tenant_id' => $tenant->id,
                    'name' => self::TEMPLATE_NAMES[$template] ?? $template,
                    'is_system' => true,
                    'base_template' => $template,
                ]);

                foreach (PermissionCatalog::expand($patterns) as $code) {
                    RolePermission::create([
                        'tenant_id' => $tenant->id,
                        'role_id' => $role->id,
                        'permission_code' => $code,
                        'effect' => 'allow',
                    ]);
                }
            }

            return ['tenant' => $tenant, 'owner' => $owner];
        }));
    }
}
