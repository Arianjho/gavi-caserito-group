<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Support\Facades\Route;

/**
 * Registra las rutas estándar de un maestro con ciclo de vida.
 */
final class ManagedRoutes
{
    /**
     * Declara listado, alta, edición, activación y eliminación con sus permisos.
     *
     * @param string $path Segmento de la URL (por ejemplo `companies`).
     * @param class-string $controller Controlador que extiende `ManagedResourceController`.
     * @param string $permission Prefijo de permiso (`modulo.recurso`).
     */
    public static function register(string $path, string $controller, string $permission): void
    {
        Route::get($path, [$controller, 'index'])
            ->middleware("permission:{$permission}.view")->name("{$path}.index");
        Route::post($path, [$controller, 'store'])
            ->middleware("permission:{$permission}.create")->name("{$path}.store");
        Route::put("{$path}/{id}", [$controller, 'update'])
            ->middleware("permission:{$permission}.update")->name("{$path}.update");
        Route::patch("{$path}/{id}/active", [$controller, 'setActive'])
            ->middleware("permission:{$permission}.update")->name("{$path}.active");
        Route::delete("{$path}/{id}", [$controller, 'destroy'])
            ->middleware("permission:{$permission}.delete")->name("{$path}.destroy");
    }
}
