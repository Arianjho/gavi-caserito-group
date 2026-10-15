<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Branding\BrandProfile;
use App\Modules\Platform\Application\Branding\DomainVerifier;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dominio propio de la cuenta: registro, verificación por DNS y resolución pública.
 */
class BrandDomainController extends Controller
{
    /**
     * @param BrandProfile $brand Lectura de la marca.
     */
    public function __construct(private readonly BrandProfile $brand) {}

    /**
     * Registra (o cambia, o quita) el dominio propio.
     *
     * Un dominio nuevo queda sin verificar y recibe un valor para publicar en DNS.
     *
     * @param Request $request Campo `domain`; vacío quita el dominio.
     */
    public function update(Request $request): JsonResponse
    {
        $request->merge(['domain' => ($raw = $request->input('domain')) === null ? null : mb_strtolower(trim((string) $raw))]);
        $data = $request->validate([
            'domain' => [
                'nullable', 'string', 'max:120',
                'regex:/^(?=.{4,120}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    $taken = TenantContext::asProvider(fn (): bool => DB::table('core.tenants')
                        ->whereRaw('lower(custom_domain) = ?', [$value])
                        ->where('id', '<>', (string) $request->user()?->tenant_id)->exists());
                    if ($taken) {
                        $fail('Ese dominio ya está en uso por otra cuenta.');
                    }
                },
            ],
        ], ['domain.regex' => 'Escribe solo el dominio, por ejemplo tienda.midominio.pe.']);
        $domain = ($data['domain'] ?? '') === '' ? null : $data['domain'];

        DB::table('core.tenants')->where('id', (string) $request->user()?->tenant_id)->update([
            'custom_domain' => $domain,
            'custom_domain_token' => $domain === null ? null : 'caserito-verify-'.bin2hex(random_bytes(12)),
            'custom_domain_verified_at' => null,
            'updated_at' => now(),
        ]);

        return $this->state((string) $request->user()?->tenant_id);
    }

    /**
     * Verifica por DNS que el dominio es del cliente.
     *
     * @param Request $request Petición autenticada.
     */
    public function verify(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()?->tenant_id;
        $row = DB::table('core.tenants')->where('id', $tenantId)->first(['custom_domain', 'custom_domain_token']);
        if ($row === null || $row->custom_domain === null) {
            return response()->json(['code' => 'domain_missing', 'message' => 'Primero registra un dominio.'], 422);
        }
        if (! app(DomainVerifier::class)->hasToken((string) $row->custom_domain, (string) $row->custom_domain_token)) {
            return response()->json([
                'code' => 'domain_not_verified',
                'message' => 'Aún no vemos el registro DNS. Puede tardar unos minutos en propagarse; intenta de nuevo luego.',
            ], 422);
        }
        DB::table('core.tenants')->where('id', $tenantId)->update(['custom_domain_verified_at' => now(), 'updated_at' => now()]);

        return $this->state($tenantId);
    }

    /**
     * Marca pública del dominio con el que se abre la aplicación (sin sesión).
     *
     * @param Request $request Parámetro `domain` con el nombre de host del navegador.
     */
    public function resolve(Request $request): JsonResponse
    {
        $host = mb_strtolower(trim((string) $request->query('domain', '')));
        $brand = $host === '' ? null : $this->brand->forDomain($host);

        return response()->json(['data' => $brand ?? $this->brand->defaults()]);
    }

    /**
     * Estado del dominio de la cuenta.
     *
     * @param string $tenantId Cuenta.
     */
    private function state(string $tenantId): JsonResponse
    {
        $row = DB::table('core.tenants')->where('id', $tenantId)
            ->first(['custom_domain', 'custom_domain_token', 'custom_domain_verified_at']);

        return response()->json(['data' => [
            'name' => $row->custom_domain ?? null,
            'token' => $row->custom_domain_token ?? null,
            'verified_at' => $row->custom_domain_verified_at ?? null,
            'record' => $row?->custom_domain === null ? null : [
                'type' => 'TXT',
                'host' => DomainVerifier::RECORD_PREFIX.'.'.$row->custom_domain,
                'value' => $row->custom_domain_token,
            ],
        ]]);
    }
}
