<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Application\PermissionChecker;
use App\Modules\Platform\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el usuario autenticado tenga un permiso.
 *
 * Uso en rutas: `->middleware('permission:orders.order.view')`.
 */
final class RequirePermission
{
    /**
     * Crea el middleware.
     *
     * @param PermissionChecker $checker Evaluador de permisos.
     */
    public function __construct(private readonly PermissionChecker $checker) {}

    /**
     * Responde 403 si el usuario no tiene el permiso requerido.
     *
     * @param Request $request Petición entrante.
     * @param Closure(Request): Response $next Siguiente capa.
     * @param string $permission Código del permiso requerido.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->checker->can($user, $permission)) {
            return response()->json([
                'code' => 'permission_denied',
                'message' => 'No tienes permiso para realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
