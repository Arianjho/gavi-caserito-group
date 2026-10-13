<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Sucursal o local de una empresa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_main
 * @property string $status
 */
class Branch extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.branches';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'company_id', 'code', 'name', 'address', 'ubigeo', 'is_main'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_main' => 'boolean'];
    }
}
