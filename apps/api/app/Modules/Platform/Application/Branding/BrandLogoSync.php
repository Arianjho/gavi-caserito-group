<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Branding;

use App\Modules\Fiscal\Application\EfactCredentials;
use App\Modules\Fiscal\Application\FiscalException;
use App\Modules\Fiscal\Application\GaviEfactClient;
use Illuminate\Support\Facades\DB;

/**
 * Envía el logo de la cuenta a Gavi eFact para que salga en el PDF de comprobantes y guías.
 */
final class BrandLogoSync
{
    /**
     * @param BrandAssets $assets Archivos de marca.
     * @param GaviEfactClient $efact Cliente de eFact.
     */
    public function __construct(private readonly BrandAssets $assets, private readonly GaviEfactClient $efact) {}

    /**
     * Sincroniza el logo con cada empresa de la cuenta que tenga eFact configurado.
     *
     * @param string $tenantId Cuenta.
     * @return list<array{company_id: string, name: string, synced: bool, error: string|null}>
     */
    public function sync(string $tenantId): array
    {
        $path = $this->assets->absolutePath($tenantId, 'logo');
        $companies = DB::table('core.companies as c')
            ->join('fiscal.efact_settings as e', 'e.company_id', '=', 'c.id')
            ->where('c.tenant_id', $tenantId)->where('c.status', 'active')
            ->get(['c.id', 'c.trade_name', 'c.legal_name']);

        $result = [];
        foreach ($companies as $company) {
            $result[] = $this->one((string) $company->id, (string) ($company->trade_name ?? $company->legal_name), $path);
        }

        return $result;
    }

    /**
     * Sincroniza una empresa.
     *
     * @param string $companyId Empresa.
     * @param string $name Nombre para mostrar.
     * @param string|null $path Archivo del logo o `null` si no hay.
     * @return array{company_id: string, name: string, synced: bool, error: string|null}
     */
    private function one(string $companyId, string $name, ?string $path): array
    {
        if ($path === null) {
            return ['company_id' => $companyId, 'name' => $name, 'synced' => false, 'error' => 'Sube un logo principal primero.'];
        }
        try {
            $this->efact->uploadLogo(EfactCredentials::forCompany($companyId), $path);
        } catch (FiscalException $e) {
            return ['company_id' => $companyId, 'name' => $name, 'synced' => false, 'error' => $e->getMessage()];
        }

        return ['company_id' => $companyId, 'name' => $name, 'synced' => true, 'error' => null];
    }
}
