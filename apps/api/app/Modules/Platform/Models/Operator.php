<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Operador del proveedor del servicio (panel SaaS). No pertenece a ninguna cuenta.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $status
 */
class Operator extends Authenticatable
{
    use HasApiTokens;
    use HasUuids;

    /** @var string */
    protected $table = 'core.operators';

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime'];
    }
}
