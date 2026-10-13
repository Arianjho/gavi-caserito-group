<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Permiso concedido (`allow`) o denegado (`deny`) a un perfil.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $role_id
 * @property string $permission_code
 * @property string $effect
 */
class RolePermission extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'core.role_permissions';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'role_id', 'permission_code', 'effect'];
}
