<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Credenciales de Gavi eFact de una empresa, descifradas solo al usarlas.
 */
final class EfactCredentials
{
    /**
     * Descifra la conexión de una empresa.
     *
     * @param string $companyId Empresa.
     * @return array{base_url: string, api_key: string, api_secret: string}
     *
     * @throws FiscalException Si la empresa no tiene eFact configurado.
     */
    public static function forCompany(string $companyId): array
    {
        $settings = DB::table('fiscal.efact_settings')->where('company_id', $companyId)->first();
        if ($settings === null) {
            throw new FiscalException('La empresa no tiene eFact configurado.', false);
        }

        return [
            'base_url' => (string) $settings->base_url,
            'api_key' => Crypt::decryptString((string) $settings->api_key),
            'api_secret' => Crypt::decryptString((string) $settings->api_secret),
        ];
    }
}
