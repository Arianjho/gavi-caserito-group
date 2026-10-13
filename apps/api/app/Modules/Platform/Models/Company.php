<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Empresa (una por RUC) dentro de una cuenta.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $tax_id
 * @property string $fiscal_mode
 * @property string $status
 */
class Company extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'core.companies';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'legal_name', 'trade_name', 'tax_id', 'address', 'ubigeo', 'phone', 'email',
        'igv_applies', 'base_currency', 'fiscal_mode', 'brand_color',
    ];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['igv_applies' => 'boolean'];
    }
}
