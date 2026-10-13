<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Almacén de una sucursal, donde se guarda el stock.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $branch_id
 * @property string $name
 * @property string $kind
 * @property bool|null $allows_negative
 * @property string $status
 */
class Warehouse extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.warehouses';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'company_id', 'branch_id', 'name', 'kind', 'allows_negative'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allows_negative' => 'boolean'];
    }
}
