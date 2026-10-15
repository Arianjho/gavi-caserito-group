<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Application\FiscalRecordSync;
use App\Modules\Fiscal\Application\FiscalVoidService;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía a eFact una nota de crédito (`note`), una guía (`guide`) o una baja (`void`).
 *
 * Reintenta con espera creciente mientras eFact o SUNAT no respondan.
 */
final class SendFiscalRecord implements ShouldQueue
{
    use Queueable;

    /**
     * Intentos máximos antes de dejarlo para el reconciliador.
     */
    public function tries(): int
    {
        return 20;
    }

    /**
     * Crea el trabajo.
     *
     * @param string $tenantId Cuenta propietaria.
     * @param string $kind `note`, `guide` o `void`.
     * @param string $recordId Documento local.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $kind,
        public readonly string $recordId,
    ) {}

    /**
     * Esperas entre reintentos, en segundos.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 60, 120, 300, 600, 900, 1800, 3600];
    }

    /**
     * Ejecuta el envío dentro del contexto de la cuenta.
     *
     * @param FiscalRecordSync $sync Envío de notas y guías.
     * @param FiscalVoidService $voids Baja de comprobantes.
     */
    public function handle(FiscalRecordSync $sync, FiscalVoidService $voids): void
    {
        $previous = TenantContext::current();
        TenantContext::useTenant($this->tenantId);
        try {
            $retry = $this->kind === 'void' ? $voids->send($this->recordId) : $sync->send($this->kind, $this->recordId);
            if ($retry) {
                $this->release($this->backoff()[min($this->attempts() - 1, 7)]);
            }
        } finally {
            $previous === '' ? TenantContext::clear() : TenantContext::useTenant($previous);
        }
    }
}
