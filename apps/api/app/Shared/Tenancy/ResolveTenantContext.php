<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Modules\Platform\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware que autentica al usuario por su token (401 si no es válido) y
 * activa el contexto de su cuenta para que la base de datos aísle los datos.
 *
 * Para localizar al usuario necesita ver su fila antes de conocer la cuenta,
 * por lo que usa brevemente el contexto de proveedor. Rechaza tokens que no
 * sean de usuarios de cuenta (por ejemplo, de operadores) y las cuentas
 * suspendidas o cerradas.
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

            if (! $user instanceof User) {
                TenantContext::clear();

                return response()->json([
                    'code' => 'unauthenticated',
                    'message' => 'Debes iniciar sesión.',
                ], 401);
            }

            if (in_array(DB::table('core.tenants')->where('id', $user->tenant_id)->value('plan_status'), ['suspended', 'closed'], true)) {
                TenantContext::clear();

                return response()->json([
                    'code' => 'account_suspended',
                    'message' => 'Tu cuenta está suspendida. Comunícate con soporte.',
                ], 403);
            }

            $request->setUserResolver(fn () => $user);
            TenantContext::useTenant((string) $user->tenant_id);

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
