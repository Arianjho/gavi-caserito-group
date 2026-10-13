<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\Operator;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Ingreso de operadores del proveedor al panel SaaS.
 */
class OperatorAuthController extends Controller
{
    /**
     * Inicia sesión de operador con correo y contraseña.
     *
     * @param Request $request Petición con `email` y `password`.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $operator = Operator::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])
            ->where('status', 'active')->first();

        if ($operator === null || ! Hash::check($data['password'], $operator->password)) {
            return response()->json([
                'code' => 'invalid_credentials', 'message' => 'Correo o contraseña incorrectos.',
            ], 401);
        }

        $token = TenantContext::asProvider(function () use ($operator): string {
            $operator->forceFill(['last_login_at' => now()])->save();

            return $operator->createToken('operator')->plainTextToken;
        });

        return response()->json([
            'token' => $token,
            'operator' => ['id' => $operator->id, 'name' => $operator->name, 'email' => $operator->email],
        ]);
    }

    /**
     * Devuelve el operador autenticado.
     *
     * @param Request $request Petición autenticada como operador.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var Operator $operator */
        $operator = $request->user();

        return response()->json(['operator' => [
            'id' => $operator->id, 'name' => $operator->name, 'email' => $operator->email,
        ]]);
    }

    /**
     * Cierra la sesión revocando el token en uso.
     *
     * @param Request $request Petición autenticada como operador.
     */
    public function logout(Request $request): JsonResponse
    {
        TenantContext::asProvider(function () use ($request): void {
            $request->user()?->currentAccessToken()->delete();
        });

        return response()->json(['message' => 'Sesión cerrada.']);
    }
}
