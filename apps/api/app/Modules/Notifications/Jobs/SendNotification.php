<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Application\NotificationService;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envía un mensaje de WhatsApp con reintentos espaciados ante cortes.
 */
final class SendNotification implements ShouldQueue
{
    use Queueable;

    /**
     * Intentos máximos.
     */
    public function tries(): int
    {
        return 5;
    }

    /**
     * Crea el trabajo.
     *
     * @param string $tenantId Cuenta propietaria.
     * @param string $messageId Mensaje encolado.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $messageId,
    ) {}

    /**
     * Segundos de espera entre intentos.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    /**
     * Ejecuta el envío dentro del contexto de la cuenta.
     *
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function handle(NotificationService $service): void
    {
        $previous = TenantContext::current();
        TenantContext::useTenant($this->tenantId);
        try {
            if ($service->send($this->messageId)) {
                $this->release($this->backoff()[min($this->attempts() - 1, 3)]);
            }
        } finally {
            $previous === '' ? TenantContext::clear() : TenantContext::useTenant($previous);
        }
    }
}
