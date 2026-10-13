<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Caja de cobro de una sucursal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $name
 * @property bool $requires_session
 * @property string $status
 */
class CashRegister extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cash.cash_registers';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'branch_id', 'name', 'requires_session'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['requires_session' => 'boolean'];
    }
}
