<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Variante vendible de un ítem (talla, color, presentación...).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $item_id
 * @property string|null $sku
 * @property string|null $name
 * @property array<string, string>|null $attributes
 * @property bool $is_default
 * @property string|null $cost_reference
 * @property string|null $sale_price
 * @property string $status
 */
class ItemVariant extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'cat.item_variants';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'item_id', 'sku', 'name', 'attributes', 'is_default', 'cost_reference', 'sale_price', 'weight'];

    /**
     * Conversiones de atributos.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'attributes' => 'array'];
    }

    /**
     * Códigos de barras de la variante.
     *
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'variant_id');
    }
}
