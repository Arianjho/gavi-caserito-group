<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regla de borrado basada en tablas dependientes.
 *
 * El registro no se elimina si otra tabla tiene filas vigentes (no
 * eliminadas) que lo referencian.
 */
final class TableDependencyGuard implements DeletionGuard
{
    /**
     * Crea la regla.
     *
     * @param array<string, array{table: string, column: string}> $dependencies Nombre legible → tabla y columna que referencia al registro.
     * @param bool $deactivatable Si se ofrece desactivar como alternativa.
     */
    public function __construct(
        private readonly array $dependencies,
        private readonly bool $deactivatable = true,
    ) {}

    /**
     * Cuenta las dependencias vigentes del registro.
     *
     * @param Model $model Registro a eliminar.
     * @return array<string, int>
     */
    public function blockers(Model $model): array
    {
        $result = [];
        foreach ($this->dependencies as $label => $dependency) {
            $result[$label] = DB::table($dependency['table'])
                ->where($dependency['column'], $model->getKey())
                ->where('status', '!=', 'deleted')
                ->count();
        }

        return $result;
    }

    /**
     * Indica si el registro puede desactivarse en lugar de eliminarse.
     */
    public function canDeactivate(): bool
    {
        return $this->deactivatable;
    }
}
