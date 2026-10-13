<?php

declare(strict_types=1);

namespace App\Modules\Platform\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * Token de acceso personal almacenado en el esquema `core`.
 */
class PersonalAccessToken extends SanctumToken
{
    /** @var string */
    protected $table = 'core.personal_access_tokens';
}
