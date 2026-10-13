<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Models\Role;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regla de borrado de perfiles: no se eliminan si tienen usuarios asignados.
 */
final class RoleDeletionGuard implements DeletionGuard
{
    /**
     * Cuenta los usuarios que tienen asignado el perfil.
     *
     * @param Model $model Perfil a eliminar.
     * @return array<string, int>
     */
    public function blockers(Model $model): array
    {
        /** @var Role $model */
        return [
            'Usuarios con este perfil' => DB::table('core.user_roles')->where('role_id', $model->id)->count(),
        ];
    }

    /**
     * Un perfil en uso puede desactivarse.
     */
    public function canDeactivate(): bool
    {
        return true;
    }
}
