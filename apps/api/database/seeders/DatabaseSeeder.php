<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Platform\Application\ProvisionAccount;
use App\Modules\Platform\Models\Operator;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Datos de desarrollo: una cuenta con su usuario propietario.
 *
 * Solo para entornos locales; las credenciales no son de producción.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Crea la cuenta y el usuario de desarrollo si no existen.
     */
    public function run(): void
    {
        $exists = TenantContext::asProvider(
            fn () => User::query()->where('email', 'dev@caserito.test')->exists()
        );

        if (! $exists) {
            app(ProvisionAccount::class)->handle(
                'Jugueteria Demo', 'Usuario de desarrollo', 'dev@caserito.test', 'caserito-dev'
            );
        }

        Operator::query()->firstOrCreate(
            ['email' => 'operador@caserito.test'],
            ['name' => 'Operador de desarrollo', 'password' => 'caserito-operador'],
        );
    }
}
