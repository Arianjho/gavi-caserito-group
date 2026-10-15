<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Branding;

use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Marca de una cuenta tal como la ven el panel, el portal y los mensajes.
 */
final class BrandProfile
{
    /** Columnas de `core.tenants` que componen la marca. */
    private const COLUMNS = [
        'id', 'name', 'brand_color', 'brand_tagline', 'brand_name', 'brand_email', 'brand_phone',
        'brand_footer', 'brand_logo_path', 'brand_logo_dark_path', 'brand_icon_path',
        'brand_assets_version', 'brand_powered_by', 'custom_domain', 'custom_domain_token',
        'custom_domain_verified_at',
    ];

    /**
     * Marca de una cuenta.
     *
     * @param string $tenantId Cuenta.
     * @return array<string, mixed>
     */
    public function forTenant(string $tenantId): array
    {
        $row = DB::table('core.tenants')->where('id', $tenantId)->first(self::COLUMNS);

        return $row === null ? $this->defaults() : $this->present($row);
    }

    /**
     * Marca pública de un dominio propio verificado, o `null` si no es de ninguna cuenta.
     *
     * @param string $host Nombre de dominio que abre la persona.
     * @return array<string, mixed>|null
     */
    public function forDomain(string $host): ?array
    {
        $row = TenantContext::asProvider(fn (): ?stdClass => DB::table('core.tenants')
            ->whereRaw('lower(custom_domain) = ?', [mb_strtolower($host)])
            ->whereNotNull('custom_domain_verified_at')
            ->where('status', 'active')
            ->first(self::COLUMNS));

        return $row === null ? null : $this->present($row);
    }

    /**
     * Marca sin personalizar: la del producto.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'name' => 'Gavi Caserito', 'color' => null, 'tagline' => null, 'email' => null, 'phone' => null,
            'footer' => null, 'logo_url' => null, 'logo_dark_url' => null, 'icon_url' => null,
            'powered_by' => false, 'custom' => false,
        ];
    }

    /**
     * Convierte la fila de la cuenta en la vista de marca.
     *
     * @param stdClass $row Fila de `core.tenants`.
     * @return array<string, mixed>
     */
    private function present(stdClass $row): array
    {
        $version = (int) $row->brand_assets_version;
        $url = fn (string $variant, ?string $path): ?string => $path === null
            ? null
            : "/api/v1/public/brand/{$row->id}/{$variant}?v={$version}";

        return [
            'name' => $row->brand_name ?? $row->name,
            'color' => $row->brand_color,
            'tagline' => $row->brand_tagline,
            'email' => $row->brand_email,
            'phone' => $row->brand_phone,
            'footer' => $row->brand_footer,
            'logo_url' => $url('logo', $row->brand_logo_path),
            'logo_dark_url' => $url('logo_dark', $row->brand_logo_dark_path),
            'icon_url' => $url('icon', $row->brand_icon_path),
            'powered_by' => (bool) $row->brand_powered_by,
            'custom' => true,
        ];
    }
}
