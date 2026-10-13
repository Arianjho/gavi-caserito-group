<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Lista de precios alternativa al precio base (mayorista, distribuidor...).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $status
 */
class PriceList extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.price_lists';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name'];
}
