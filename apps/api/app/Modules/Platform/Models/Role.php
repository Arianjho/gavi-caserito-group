<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de acceso: agrupa permisos y se asigna a usuarios.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property string $status
 */
class Role extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.roles';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'description', 'is_system', 'base_template'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * Permisos concedidos o denegados al perfil.
     *
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }
}
