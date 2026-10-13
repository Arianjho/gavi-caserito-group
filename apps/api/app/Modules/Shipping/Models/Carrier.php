<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Models;

use App\Shared\Models\Concerns\HasAudit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Agencia de transporte o courier.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $kind
 * @property string $status
 */
class Carrier extends Model
{
    use HasAudit;
    use HasUuids;

    /** @var string */
    protected $table = 'ship.carriers';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'name', 'kind', 'tracking_url_template', 'phone', 'notes'];
}
