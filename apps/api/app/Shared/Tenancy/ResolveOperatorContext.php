<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Modules\Platform\Models\Operator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware del panel SaaS: solo deja pasar a operadores activos y activa el
 * contexto de proveedor durante la petición.
 *
 * Un token de usuario de una cuenta nunca sirve aquí, y el de un operador
 * tampoco sirve en las rutas de las cuentas (ver {@see ResolveTenantContext}).
 */
final class ResolveOperatorContext
{
    /**
     * Procesa la petición con acceso de proveedor.
     *
     * @param Request $request Petición entrante.
     * @param Closure(Request): Response $next Siguiente capa.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            TenantContext::useProvider();
            $operator = $request->user('sanctum');

            if (! $operator instanceof Operator || $operator->status !== 'active') {
                TenantContext::clear();

                return response()->json([
                    'code' => 'unauthenticated', 'message' => 'Debes iniciar sesión como operador.',
                ], 401);
            }

            $request->setUserResolver(fn () => $operator);

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
