<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Fiscal\Application\FiscalEngine;
use App\Modules\Fiscal\Application\GaviEfactClient;
use App\Modules\Notifications\Application\EvolutionApiNotifier;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Platform\Application\PermissionChecker;
use App\Modules\Platform\Http\Middleware\RequirePermission;
use App\Modules\Platform\Models\PersonalAccessToken;
use App\Shared\Database\BlueprintMacros;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Proveedor de servicios principal de la aplicación.
 *
 * Aquí se enlazan las interfaces (puertos) con sus implementaciones cuando no
 * pertenecen a un módulo específico.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra los servicios de la aplicación en el contenedor.
     */
    public function register(): void
    {
        $this->app->singleton(PermissionChecker::class);
        $this->app->bind(FiscalEngine::class, GaviEfactClient::class);
        $this->app->bind(Notifier::class, EvolutionApiNotifier::class);
    }

    /**
     * Inicializa los servicios de la aplicación una vez registrados.
     */
    public function boot(): void
    {
        BlueprintMacros::register();
        Route::aliasMiddleware('permission', RequirePermission::class);
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
