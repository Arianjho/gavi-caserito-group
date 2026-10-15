<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Jobs\SendFiscalDocument;
use App\Modules\Fiscal\Jobs\SendFiscalRecord;
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
     * Reenvía lo que nunca llegó a eFact y consulta lo que quedó sin estado final.
     *
     * @param int $olderThanMinutes Antigüedad mínima sin cambios.
     * @return array{resent: int, refreshed: int}
     */
    public function run(int $olderThanMinutes = 10): array
    {
        $resent = 0;
        $refreshed = 0;
        foreach ($this->stuck($olderThanMinutes) as $item) {
            TenantContext::useTenant($item['tenant_id']);
            try {
                $this->reconcile($item) ? $resent++ : $refreshed++;
            } finally {
                TenantContext::clear();
            }
        }

        return ['resent' => $resent, 'refreshed' => $refreshed];
    }

    /**
     * Documentos detenidos de todas las cuentas.
     *
     * @param int $olderThanMinutes Antigüedad mínima sin cambios.
     * @return list<array{kind: string, id: string, tenant_id: string, sent: bool}>
     */
    private function stuck(int $olderThanMinutes): array
    {
        $cutoff = now()->subMinutes($olderThanMinutes);

        return TenantContext::asProvider(function () use ($cutoff): array {
            $items = [];
            $sources = [
                'document' => DB::table('fiscal.documents')->whereIn('status', ['queued', 'pending'])
                    ->where('updated_at', '<', $cutoff)->get(['id', 'tenant_id', 'efact_id']),
                'note' => DB::table('fiscal.credit_notes')->whereIn('status', ['queued', 'pending'])
                    ->where('updated_at', '<', $cutoff)->get(['id', 'tenant_id', 'efact_id']),
                'guide' => DB::table('fiscal.dispatch_guides')->whereIn('status', ['queued', 'pending'])
                    ->where('updated_at', '<', $cutoff)->get(['id', 'tenant_id', 'efact_id']),
                'void' => DB::table('fiscal.documents')->where('void_status', 'pending')
                    ->where('updated_at', '<', $cutoff)->get(['id', 'tenant_id', 'void_efact_id as efact_id']),
            ];
            foreach ($sources as $kind => $rows) {
                foreach ($rows as $row) {
                    $items[] = [
                        'kind' => $kind, 'id' => (string) $row->id, 'tenant_id' => (string) $row->tenant_id,
                        'sent' => $row->efact_id !== null,
                    ];
                }
            }

            return $items;
        });
    }

    /**
     * Reenvía o consulta un documento detenido.
     *
     * @param array{kind: string, id: string, tenant_id: string, sent: bool} $item Documento detenido.
     * @return bool `true` si se reenvió, `false` si solo se consultó.
     */
    private function reconcile(array $item): bool
    {
        if (! $item['sent']) {
            $item['kind'] === 'document'
                ? SendFiscalDocument::dispatch($item['tenant_id'], $item['id'])
                : SendFiscalRecord::dispatch($item['tenant_id'], $item['kind'], $item['id']);

            return true;
        }
        match ($item['kind']) {
            'document' => $this->service->refresh($item['id']),
            'void' => app(FiscalVoidService::class)->refresh($item['id']),
            default => app(FiscalRecordSync::class)->refresh($item['kind'], $item['id']),
        };
        $table = $item['kind'] === 'note' ? 'fiscal.credit_notes' : ($item['kind'] === 'guide' ? 'fiscal.dispatch_guides' : 'fiscal.documents');
        DB::table($table)->where('id', $item['id'])->update(['updated_at' => now()]);

        return false;
    }
}
