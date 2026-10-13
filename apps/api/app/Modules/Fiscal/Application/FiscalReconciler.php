<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Jobs\SendFiscalDocument;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Red de seguridad del flujo fiscal: revisa los comprobantes que quedaron
 * sin estado final (webhook perdido, trabajo caído) y los vuelve a mover.
 */
final class FiscalReconciler
{
    /**
     * Crea el reconciliador.
     *
     * @param FiscalService $service Servicio fiscal.
     */
    public function __construct(private readonly FiscalService $service) {}

    /**
     * Reconcilia los comprobantes sin estado final de todas las cuentas.
     *
     * @param int $olderThanMinutes Antigüedad mínima desde el último cambio.
     * @return array{resent: int, refreshed: int}
     */
    public function run(int $olderThanMinutes = 10): array
    {
        $stuck = TenantContext::asProvider(fn () => DB::table('fiscal.documents')
            ->whereIn('status', ['queued', 'pending'])
            ->where('updated_at', '<', now()->subMinutes($olderThanMinutes))
            ->orderBy('updated_at')->limit(500)
            ->get(['id', 'tenant_id', 'efact_id'])->all());

        $resent = 0;
        $refreshed = 0;
        foreach ($stuck as $doc) {
            if ($doc->efact_id === null) {
                SendFiscalDocument::dispatch((string) $doc->tenant_id, (string) $doc->id);
                $resent++;

                continue;
            }
            TenantContext::useTenant((string) $doc->tenant_id);
            try {
                $this->service->refresh((string) $doc->id);
                DB::table('fiscal.documents')->where('id', $doc->id)->update(['updated_at' => now()]);
            } finally {
                TenantContext::clear();
            }
            $refreshed++;
        }

        return ['resent' => $resent, 'refreshed' => $refreshed];
    }
}
