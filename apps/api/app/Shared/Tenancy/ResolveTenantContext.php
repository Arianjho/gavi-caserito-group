<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que autentica al usuario por su token (401 si no es válido) y
 * activa el contexto de su cuenta para que la base de datos aísle los datos.
 *
 * Para localizar al usuario necesita ver su fila antes de conocer la cuenta,
 * por lo que usa brevemente el contexto de proveedor.
 */
final class ResolveTenantContext
{
    /**
     * Procesa la petición dentro del contexto de la cuenta del usuario.
     *
     * @param Request $request Petición entrante.
     * @param Closure(Request): Response $next Siguiente capa.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            TenantContext::useProvider();
            $user = $request->user('sanctum');

            if ($user === null) {
                TenantContext::clear();

                return response()->json([
                    'code' => 'unauthenticated',
                    'message' => 'Debes iniciar sesión.',
                ], 401);
            }

            $request->setUserResolver(fn () => $user);
            TenantContext::useTenant((string) $user->tenant_id);

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
