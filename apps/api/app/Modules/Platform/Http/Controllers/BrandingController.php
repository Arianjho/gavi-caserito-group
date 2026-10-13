<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Marca del negocio: color y lema que ve el cliente final.
 */
class BrandingController extends Controller
{
    /**
     * Devuelve la marca actual.
     *
     * @param Request $request Petición autenticada.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->current((string) $request->user()?->tenant_id)]);
    }

    /**
     * Actualiza color y lema.
     *
     * @param Request $request Datos: `brand_color` y `brand_tagline`.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_tagline' => ['nullable', 'string', 'max:120'],
        ]);
        $tenantId = (string) $request->user()?->tenant_id;
        DB::table('core.tenants')->where('id', $tenantId)->update([
            'brand_color' => $data['brand_color'] ?? null,
            'brand_tagline' => $data['brand_tagline'] ?? null,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => $this->current($tenantId)]);
    }

    /**
     * Lee la marca de una cuenta.
     *
     * @param string $tenantId Cuenta.
     * @return array<string, string|null>
     */
    private function current(string $tenantId): array
    {
        $row = DB::table('core.tenants')->where('id', $tenantId)->first(['brand_color', 'brand_tagline']);

        return ['brand_color' => $row->brand_color ?? null, 'brand_tagline' => $row->brand_tagline ?? null];
    }
}
