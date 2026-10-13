<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Informa el estado de la API y de su conexión con la base de datos.
 *
 * Se usa para monitoreo y para verificar despliegues; no expone datos sensibles.
 */
class HealthController extends Controller
{
    /**
     * Devuelve el estado de salud de la aplicación.
     */
    public function __invoke(): JsonResponse
    {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'database' => DB::connection()->getDriverName(),
            'time' => now()->toIso8601String(),
        ]);
    }
}
