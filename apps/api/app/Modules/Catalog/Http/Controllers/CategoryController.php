<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Application\CatalogDeletionGuards;
use App\Modules\Catalog\Models\Category;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Gestión de categorías de productos.
 */
class CategoryController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Category::class;
    }

    /**
     * Regla de borrado de categorías.
     */
    protected function guard(): DeletionGuard
    {
        return CatalogDeletionGuards::forCategory();
    }

    /**
     * Reglas de validación de una categoría.
     *
     * @param Model|null $current Categoría en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'parent_id' => [
                'nullable', 'uuid', Rule::exists(Category::class, 'id')->whereNot('status', 'deleted'),
                Rule::notIn([$current?->getKey()]),
            ],
        ];
    }
}
