<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto o servicio; agrupa una o más variantes vendibles.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string|null $code
 * @property string $name
 * @property string|null $description
 * @property string|null $category_id
 * @property string|null $brand_id
 * @property string $base_uom
 * @property bool $tracks_stock
 * @property bool $has_variants
 * @property string $status
 */
class Item extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.items';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id', 'kind', 'code', 'name', 'description', 'category_id', 'brand_id',
        'base_uom', 'tracks_stock', 'has_variants', 'default_tax_affectation',
    ];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tracks_stock' => 'boolean', 'has_variants' => 'boolean'];
    }

    /**
     * Variantes no eliminadas del ítem.
     *
     * @return HasMany<ItemVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ItemVariant::class, 'item_id')->where('status', '!=', 'deleted');
    }
}
