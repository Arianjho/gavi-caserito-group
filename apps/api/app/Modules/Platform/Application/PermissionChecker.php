<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resuelve los permisos efectivos de un usuario.
 *
 * Reglas: el propietario de la cuenta tiene todos; para el resto se unen los
 * permisos de sus perfiles activos y cualquier `deny` prevalece sobre `allow`.
 */
final class PermissionChecker
{
    /**
     * Caché por usuario durante la petición.
     *
     * @var array<string, list<string>>
     */
    private array $cache = [];

    /**
     * Lista los permisos efectivos de un usuario.
     *
     * @param User $user Usuario a evaluar.
     * @return list<string>
     */
    public function effective(User $user): array
    {
        if (isset($this->cache[$user->id])) {
            return $this->cache[$user->id];
        }

        if ($user->is_owner) {
            return $this->cache[$user->id] = PermissionCatalog::all();
        }

        $rows = DB::table('core.user_roles as ur')
            ->join('core.roles as r', 'r.id', '=', 'ur.role_id')
            ->join('core.role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->where('ur.user_id', $user->id)
            ->where('r.status', 'active')
            ->get(['rp.permission_code', 'rp.effect']);

        $allowed = [];
        $denied = [];
        foreach ($rows as $row) {
            if ($row->effect === 'deny') {
                $denied[$row->permission_code] = true;
            } else {
                $allowed[$row->permission_code] = true;
            }
        }

        return $this->cache[$user->id] = array_values(
            array_diff(array_keys($allowed), array_keys($denied))
        );
    }

    /**
     * Indica si el usuario tiene un permiso.
     *
     * @param User $user Usuario a evaluar.
     * @param string $code Código de permiso.
     */
    public function can(User $user, string $code): bool
    {
        return in_array($code, $this->effective($user), true);
    }
}
