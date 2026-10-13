<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tercero: cliente y/o proveedor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $doc_type
 * @property string|null $doc_number
 * @property string $name
 * @property bool $is_customer
 * @property bool $is_supplier
 * @property string $status
 */
class Party extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.parties';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'doc_type', 'doc_number', 'name', 'trade_name', 'phone', 'email',
        'address', 'ubigeo', 'is_customer', 'is_supplier', 'notes', 'price_list_id', 'whatsapp_opt_in',
    ];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_customer' => 'boolean', 'is_supplier' => 'boolean', 'whatsapp_opt_in' => 'boolean'];
    }
}
