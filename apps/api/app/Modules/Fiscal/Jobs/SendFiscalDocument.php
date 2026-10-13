<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Application\FiscalService;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía un comprobante a eFact con reintentos espaciados.
 *
 * Un corte de eFact o de SUNAT deja el comprobante en cola y no afecta la
 * venta. Tras agotar los intentos queda en `error` para revisión manual.
 */
final class SendFiscalDocument implements ShouldQueue
{
    use Queueable;

    /**
     * Intentos máximos (cubren varias horas de corte).
     */
    public function tries(): int
    {
        return 20;
    }

    /**
     * Crea el trabajo.
     *
     * @param string $tenantId Cuenta propietaria.
     * @param string $documentId Comprobante local.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $documentId,
    ) {}

    /**
     * Segundos de espera crecientes entre intentos.
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
     * @param FiscalService $service Servicio fiscal.
     */
    public function handle(FiscalService $service): void
    {
        $previous = TenantContext::current();
        TenantContext::useTenant($this->tenantId);
        try {
            if ($service->send($this->documentId)) {
                $this->release($this->backoff()[min($this->attempts() - 1, 7)]);
            }
        } finally {
            $previous === '' ? TenantContext::clear() : TenantContext::useTenant($previous);
        }
    }
}
