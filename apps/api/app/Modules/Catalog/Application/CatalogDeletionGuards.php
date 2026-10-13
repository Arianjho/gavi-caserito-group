<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;

/**
 * Reglas de borrado del catálogo.
 *
 * Los ítems sumarán el bloqueo por documentos y movimientos cuando existan
 * los módulos de ventas e inventario (ver matriz §18.9 del modelo de datos).
 */
final class CatalogDeletionGuards
{
    /**
     * Regla para categorías: sin subcategorías ni ítems vigentes.
     */
    public static function forCategory(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Subcategorías vigentes' => ['table' => 'cat.categories', 'column' => 'parent_id'],
            'Productos en la categoría' => ['table' => 'cat.items', 'column' => 'category_id'],
        ]);
    }

    /**
     * Regla para marcas: sin ítems vigentes.
     */
    public static function forBrand(): DeletionGuard
    {
        return new TableDependencyGuard([
            'Productos de la marca' => ['table' => 'cat.items', 'column' => 'brand_id'],
        ]);
    }

    /**
     * Regla para ítems.
     */
    public static function forItem(): DeletionGuard
    {
        return new TableDependencyGuard([]);
    }
}
