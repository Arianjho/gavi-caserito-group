<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Muestra el plan de la cuenta y cuánto lleva usado.
 */
class PlanController extends Controller
{
    /**
     * Devuelve plan, límites y uso.
     *
     * @param Request $request Petición autenticada.
     * @param PlanLimits $limits Servicio de límites.
     */
    public function show(Request $request, PlanLimits $limits): JsonResponse
    {
        return response()->json(['data' => $limits->summary((string) $request->user()?->tenant_id)]);
    }
}
