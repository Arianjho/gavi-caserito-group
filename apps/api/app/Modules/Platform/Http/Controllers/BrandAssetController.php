<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\Branding\BrandAssets;
use App\Modules\Platform\Application\Branding\BrandLogoSync;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Logos del negocio: subida, borrado, sincronización con eFact y entrega pública.
 */
class BrandAssetController extends Controller
{
    /**
     * Reglas de imagen por variante: el logo principal es apaisado y el icono, cuadrado.
     *
     * @var array<string, string>
     */
    private const RULES = [
        'logo' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:min_width=200,min_height=40',
        'logo_dark' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:min_width=200,min_height=40',
        'icon' => 'required|image|mimes:png,webp|max:2048|dimensions:min_width=128,ratio=1/1',
    ];

    /**
     * @param BrandAssets $assets Archivos de marca.
     * @param BrandLogoSync $sync Sincronización con eFact.
     */
    public function __construct(private readonly BrandAssets $assets, private readonly BrandLogoSync $sync) {}

    /**
     * Sube un archivo de marca.
     *
     * El logo principal se envía a eFact en el mismo paso (si falla, el archivo
     * queda guardado y el resultado indica qué empresas faltan).
     *
     * @param Request $request Archivo en el campo `file`.
     * @param string $variant `logo`, `logo_dark` o `icon`.
     */
    public function store(Request $request, string $variant): JsonResponse
    {
        if (! isset(BrandAssets::VARIANTS[$variant])) {
            return response()->json(['code' => 'not_found', 'message' => 'Variante de marca desconocida.'], 404);
        }
        $request->validate(['file' => self::RULES[$variant]], [
            'file.dimensions' => $variant === 'icon'
                ? 'El icono debe ser cuadrado y de al menos 128 px.'
                : 'El logo debe medir al menos 200 × 40 px.',
            'file.max' => 'La imagen pesa más de 2 MB.',
            'file.mimes' => 'Usa una imagen PNG, JPG o WebP.',
        ]);
        $tenantId = (string) $request->user()?->tenant_id;
        $this->assets->store($tenantId, $variant, $request->file('file'));

        return response()->json(['data' => [
            'variant' => $variant,
            'efact' => $variant === 'logo' ? $this->sync->sync($tenantId) : [],
        ]], 201);
    }

    /**
     * Elimina un archivo de marca.
     *
     * @param Request $request Petición autenticada.
     * @param string $variant Variante.
     */
    public function destroy(Request $request, string $variant): JsonResponse
    {
        if (! isset(BrandAssets::VARIANTS[$variant])) {
            return response()->json(['code' => 'not_found', 'message' => 'Variante de marca desconocida.'], 404);
        }
        $this->assets->remove((string) $request->user()?->tenant_id, $variant);

        return response()->json(['data' => ['variant' => $variant]]);
    }

    /**
     * Reenvía el logo principal a eFact para todas las empresas de la cuenta.
     *
     * @param Request $request Petición autenticada.
     */
    public function syncEfact(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sync->sync((string) $request->user()?->tenant_id)]);
    }

    /**
     * Entrega el archivo de marca a quien abre el portal o el panel (sin sesión).
     *
     * @param string $tenant Cuenta dueña del archivo.
     * @param string $variant `logo`, `logo_dark` o `icon`.
     */
    public function show(string $tenant, string $variant): BinaryFileResponse|JsonResponse
    {
        $path = isset(BrandAssets::VARIANTS[$variant]) && preg_match('/^[0-9a-f-]{36}$/', $tenant) === 1
            ? TenantContext::asProvider(fn (): ?string => $this->assets->absolutePath($tenant, $variant))
            : null;
        if ($path === null) {
            return response()->json(['code' => 'not_found', 'message' => 'Archivo no encontrado.'], 404);
        }

        return response()->file($path, ['Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
    }
}
