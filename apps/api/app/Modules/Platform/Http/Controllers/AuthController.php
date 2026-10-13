<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PermissionChecker;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Inicio y cierre de sesión mediante tokens personales.
 */
class AuthController extends Controller
{
    /**
     * Inicia sesión con correo y contraseña y entrega un token.
     *
     * Responde con el mismo error para correo inexistente, contraseña errónea
     * o usuario inactivo, para no revelar qué cuentas existen.
     *
     * @param Request $request Petición con `email` y `password`.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:80'],
        ]);

        $user = TenantContext::asProvider(
            fn () => User::query()
                ->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])
                ->where('status', 'active')
                ->first()
        );

        $suspended = $user !== null && in_array(
            TenantContext::asProvider(fn () => DB::table('core.tenants')->where('id', $user->tenant_id)->value('plan_status')),
            ['suspended', 'closed'],
            true,
        );
        if ($suspended && Hash::check($data['password'], (string) $user?->password)) {
            return response()->json([
                'code' => 'account_suspended', 'message' => 'Tu cuenta está suspendida. Comunícate con soporte.',
            ], 403);
        }

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'code' => 'invalid_credentials',
                'message' => 'Correo o contraseña incorrectos.',
            ], 401);
        }

        $token = TenantContext::asProvider(function () use ($user, $data): string {
            $user->forceFill(['last_login_at' => now()])->save();

            return $user->createToken($data['device_name'] ?? 'web')->plainTextToken;
        });

        return response()->json([
            'token' => $token,
            'user' => $this->present($user),
        ]);
    }

    /**
     * Devuelve el usuario autenticado.
     *
     * @param Request $request Petición autenticada.
     * @param PermissionChecker $checker Evaluador de permisos.
     */
    public function me(Request $request, PermissionChecker $checker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->present($user),
            'permissions' => $checker->effective($user),
        ]);
    }

    /**
     * Cierra la sesión revocando el token en uso.
     *
     * @param Request $request Petición autenticada.
     */
    public function logout(Request $request): JsonResponse
    {
        TenantContext::asProvider(function () use ($request): void {
            $request->user()?->currentAccessToken()->delete();
        });

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * Datos públicos del usuario.
     *
     * @param User $user Usuario a presentar.
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'name' => $user->name,
            'email' => $user->email,
            'is_owner' => $user->is_owner,
        ];
    }
}
