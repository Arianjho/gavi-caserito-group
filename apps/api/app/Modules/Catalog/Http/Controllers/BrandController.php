<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogDeletionGuards;
use App\Modules\Catalog\Models\Brand;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;

/**
 * Gestión de marcas de productos.
 */
class BrandController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Brand::class;
    }

    /**
     * Regla de borrado de marcas.
     */
    protected function guard(): DeletionGuard
    {
        return CatalogDeletionGuards::forBrand();
    }

    /**
     * Reglas de validación de una marca.
     *
     * @param Model|null $current Marca en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return ['name' => ['required', 'string', 'max:120']];
    }
}
