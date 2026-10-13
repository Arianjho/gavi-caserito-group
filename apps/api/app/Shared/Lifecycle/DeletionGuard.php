<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use Illuminate\Database\Eloquent\Model;

/**
 * Regla de borrado de un tipo de registro.
 *
 * Cada dominio implementa la suya y declara qué dependencias impiden
 * eliminar un registro (ver `diseno/03-modelo-de-datos.md` §18.9).
 */
interface DeletionGuard
{
    /**
     * Dependencias que impiden eliminar el registro.
     *
     * @param Model $model Registro a eliminar.
     * @return array<string, int> Nombre legible de la dependencia → cantidad.
     */
    public function blockers(Model $model): array;

    /**
     * Indica si, con dependencias, el registro puede desactivarse en su lugar.
     */
    public function canDeactivate(): bool;
}
