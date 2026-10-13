<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;

/**
 * Reglas de borrado de la estructura organizacional.
 *
 * Una empresa no se elimina si tiene sucursales o almacenes vigentes; una
 * sucursal no se elimina si tiene almacenes vigentes. Los almacenes sumarán
 * su bloqueo por movimientos de stock cuando exista el módulo de inventario.
 */
final class OrganizationDeletionGuards
{
    /**
     * Regla para empresas.
     */
    public static function forCompany(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Sucursales vigentes' => ['table' => 'core.branches', 'column' => 'company_id'],
            'Almacenes vigentes' => ['table' => 'core.warehouses', 'column' => 'company_id'],
        ]);
    }

    /**
     * Regla para sucursales.
     */
    public static function forBranch(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Almacenes vigentes' => ['table' => 'core.warehouses', 'column' => 'branch_id'],
        ]);
    }

    /**
     * Regla para almacenes.
     */
    public static function forWarehouse(): DeletionGuard
    {
        return new TableDependencyGuard([]);
    }
}
