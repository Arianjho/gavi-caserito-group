<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Attribute;
use App\Shared\Http\ManagedResourceController;
use App\Shared\Lifecycle\DeletionGuard;
use App\Shared\Lifecycle\TableDependencyGuard;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Gestión del catálogo de atributos para variantes.
 */
class AttributeController extends ManagedResourceController
{
    /**
     * Modelo administrado.
     *
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Attribute::class;
    }

    /**
     * Los atributos son solo sugerencias; no tienen dependientes.
     */
    protected function guard(): DeletionGuard
    {
        return new TableDependencyGuard([]);
    }

    /**
     * Reglas de validación de un atributo.
     *
     * @param Model|null $current Atributo en edición.
     * @return array<string, mixed>
     */
    protected function rules(?Model $current): array
    {
        return [
            'name' => ['required', 'string', 'max:60', $this->uniqueName($current)],
            'suggested_values' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Regla de nombre único dentro de la cuenta, sin distinguir mayúsculas
     * y sin contar los eliminados.
     *
     * @param Model|null $current Registro en edición.
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function uniqueName(?Model $current): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            $exists = Attribute::query()
                ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                ->where('status', '<>', 'deleted')
                ->when($current !== null, fn ($query) => $query->whereKeyNot($current?->getKey()))
                ->exists();
            if ($exists) {
                $fail('Ya existe un registro con ese nombre.');
            }
        };
    }
}
