<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Atributo reutilizable para generar variantes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $suggested_values
 * @property string $status
 */
class Attribute extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.attributes';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'suggested_values'];
}
