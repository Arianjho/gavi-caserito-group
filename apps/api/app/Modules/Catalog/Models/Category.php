<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Categoría de productos (árbol).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $parent_id
 * @property string $name
 * @property string $status
 */
class Category extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.categories';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'parent_id', 'name'];
}
