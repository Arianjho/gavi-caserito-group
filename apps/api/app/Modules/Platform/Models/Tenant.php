<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cuenta del cliente: propietaria de todos sus datos.
 *
 * @property string $id
 * @property string $name
 * @property string $plan_status
 * @property string $status
 */
class Tenant extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.tenants';

    /** @var list<string> */
    protected $fillable = ['name', 'plan_status', 'trial_ends_at', 'locale', 'timezone'];
}
