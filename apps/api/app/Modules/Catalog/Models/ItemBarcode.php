<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Código de barras de una variante.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $variant_id
 * @property string $code
 * @property string $kind
 */
class ItemBarcode extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'cat.item_barcodes';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'variant_id', 'code', 'kind'];
}
