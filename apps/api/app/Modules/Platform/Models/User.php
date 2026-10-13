<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario de la plataforma, siempre perteneciente a una cuenta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $email
 * @property bool $is_owner
 * @property string $status
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.users';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'is_owner'];

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_owner' => 'boolean', 'last_login_at' => 'datetime'];
    }
}
