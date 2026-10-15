<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Branding;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Archivos de marca de una cuenta (logo, logo oscuro, icono) en disco privado.
 *
 * Se sirven por una ruta pública de la API, así que no requieren enlace simbólico
 * ni exponen el almacenamiento.
 */
final class BrandAssets
{
    /**
     * Variantes admitidas y la columna donde se guarda su ruta.
     *
     * @var array<string, string>
     */
    public const VARIANTS = [
        'logo' => 'brand_logo_path',
        'logo_dark' => 'brand_logo_dark_path',
        'icon' => 'brand_icon_path',
    ];

    /**
     * Guarda un archivo y renueva la versión para vaciar cachés.
     *
     * @param string $tenantId Cuenta.
     * @param string $variant `logo`, `logo_dark` o `icon`.
     * @param UploadedFile $file Imagen ya validada.
     */
    public function store(string $tenantId, string $variant, UploadedFile $file): void
    {
        $this->remove($tenantId, $variant);
        $extension = $file->extension() ?: 'png';
        $path = (string) $file->storeAs("brand/{$tenantId}", "{$variant}-".bin2hex(random_bytes(4)).".{$extension}", 'local');
        $this->save($tenantId, [self::VARIANTS[$variant] => $path]);
    }

    /**
     * Elimina el archivo de una variante.
     *
     * @param string $tenantId Cuenta.
     * @param string $variant Variante.
     */
    public function remove(string $tenantId, string $variant): void
    {
        $column = self::VARIANTS[$variant];
        $path = DB::table('core.tenants')->where('id', $tenantId)->value($column);
        if (is_string($path) && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
        $this->save($tenantId, [$column => null]);
    }

    /**
     * Ruta absoluta del archivo de una variante, si existe.
     *
     * @param string $tenantId Cuenta.
     * @param string $variant Variante.
     */
    public function absolutePath(string $tenantId, string $variant): ?string
    {
        $path = DB::table('core.tenants')->where('id', $tenantId)->value(self::VARIANTS[$variant]);

        return is_string($path) && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }

    /**
     * Actualiza columnas y sube la versión de los archivos.
     *
     * @param string $tenantId Cuenta.
     * @param array<string, string|null> $values Columnas por actualizar.
     */
    private function save(string $tenantId, array $values): void
    {
        DB::table('core.tenants')->where('id', $tenantId)->update($values + [
            'brand_assets_version' => DB::raw('brand_assets_version + 1'),
            'updated_at' => now(),
        ]);
    }
}
