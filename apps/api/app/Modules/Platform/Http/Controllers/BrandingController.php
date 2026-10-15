<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Branding\BrandProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marca del negocio: nombre, color, lema, contacto y pie que ve el cliente final.
 */
class BrandingController extends Controller
{
    /**
     * @param BrandProfile $brand Lectura de la marca.
     */
    public function __construct(private readonly BrandProfile $brand) {}

    /**
     * Devuelve la marca actual junto con el estado del dominio propio.
     *
     * @param Request $request Petición autenticada.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->current((string) $request->user()?->tenant_id)]);
    }

    /**
     * Marca pública de la cuenta para cualquier usuario autenticado (panel, tickets, impresos).
     *
     * @param Request $request Petición autenticada.
     */
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->brand->forTenant((string) $request->user()?->tenant_id)]);
    }

    /**
     * Actualiza los textos y el color de la marca.
     *
     * @param Request $request Datos de identidad, contacto y pie.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
            'brand_name' => ['nullable', 'string', 'max:80'],
            'brand_email' => ['nullable', 'email', 'max:120'],
            'brand_phone' => ['nullable', 'string', 'max:30'],
            'brand_footer' => ['nullable', 'string', 'max:300'],
            'brand_powered_by' => ['sometimes', 'boolean'],
        ]);
        $tenantId = (string) $request->user()?->tenant_id;
        $values = [];
        foreach (['brand_color', 'brand_tagline', 'brand_name', 'brand_email', 'brand_phone', 'brand_footer'] as $key) {
            $values[$key] = $data[$key] ?? null;
        }
        if (array_key_exists('brand_powered_by', $data)) {
            $values['brand_powered_by'] = (bool) $data['brand_powered_by'];
        }
        DB::table('core.tenants')->where('id', $tenantId)->update($values + ['updated_at' => now()]);

        return response()->json(['data' => $this->current($tenantId)]);
    }

    /**
     * Marca de la cuenta con los datos que solo ve quien la administra.
     *
     * @param string $tenantId Cuenta.
     * @return array<string, mixed>
     */
    private function current(string $tenantId): array
    {
        $row = DB::table('core.tenants')->where('id', $tenantId)
            ->first(['brand_name', 'custom_domain', 'custom_domain_token', 'custom_domain_verified_at']);
        $profile = $this->brand->forTenant($tenantId);

        return $profile + [
            'brand_color' => $profile['color'],
            'brand_tagline' => $profile['tagline'],
            'brand_name' => $row->brand_name ?? null,
            'brand_email' => $profile['email'],
            'brand_phone' => $profile['phone'],
            'brand_footer' => $profile['footer'],
            'brand_powered_by' => $profile['powered_by'],
            'domain' => [
                'name' => $row->custom_domain ?? null,
                'token' => $row->custom_domain_token ?? null,
                'verified_at' => $row->custom_domain_verified_at ?? null,
            ],
        ];
    }
}
