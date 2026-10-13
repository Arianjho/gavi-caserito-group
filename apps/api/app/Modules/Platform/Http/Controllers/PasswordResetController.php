<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Recuperación de contraseña por enlace enviado al correo.
 */
class PasswordResetController extends Controller
{
    /**
     * Minutos de vigencia del enlace.
     */
    private const TTL_MINUTES = 60;

    /**
     * Envía el enlace de recuperación si el correo pertenece a un usuario activo.
     *
     * Responde siempre igual para no revelar qué correos existen.
     *
     * @param Request $request Petición con `email`.
     */
    public function forgot(Request $request): JsonResponse
    {
        $email = mb_strtolower($request->validate(['email' => ['required', 'email', 'max:190']])['email']);

        $user = TenantContext::asProvider(fn () => User::query()
            ->whereRaw('lower(email) = ?', [$email])->where('status', 'active')->first());

        if ($user !== null) {
            $token = Str::random(64);
            DB::table('core.password_resets')->where('user_id', $user->id)->whereNull('used_at')->delete();
            DB::table('core.password_resets')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
            $link = rtrim((string) config('app.frontend_url'), '/').'/restablecer?token='.$token.'&email='.urlencode($email);
            Mail::raw(
                "Hola {$user->name},\n\nPara crear una contraseña nueva abre este enlace (vale 1 hora):\n{$link}\n\n"
                .'Si no lo pediste, ignora este mensaje.',
                fn ($message) => $message->to($email)->subject('Restablecer tu contraseña de Gavi Caserito'),
            );
        }

        return response()->json(['message' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.'], 202);
    }

    /**
     * Cambia la contraseña con un token vigente y cierra todas las sesiones.
     *
     * @param Request $request Datos: `email`, `token` y `password`.
     */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:10', 'max:100'],
        ]);

        $user = TenantContext::asProvider(fn () => User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->where('status', 'active')->first());
        $reset = $user === null ? null : DB::table('core.password_resets')
            ->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->first();

        if ($user === null || $reset === null || ! hash_equals((string) $reset->token_hash, hash('sha256', $data['token']))) {
            return response()->json([
                'code' => 'invalid_reset_token',
                'message' => 'El enlace no es válido o ya venció. Pide uno nuevo.',
            ], 422);
        }

        TenantContext::asProvider(function () use ($user, $data, $reset): void {
            $user->forceFill(['password' => $data['password']])->save();
            $user->tokens()->delete();
            DB::table('core.password_resets')->where('id', $reset->id)->update(['used_at' => now()]);
        });

        return response()->json(['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }
}
