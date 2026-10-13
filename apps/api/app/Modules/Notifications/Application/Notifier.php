<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

/**
 * Puerto de envío de mensajes. El dominio no conoce al proveedor concreto
 * (Evolution API hoy; otro canal mañana).
 */
interface Notifier
{
    /**
     * Envía un texto a un teléfono.
     *
     * @param array{base_url: string, instance: string, api_key: string} $account Conexión del negocio.
     * @param string $phone Teléfono en formato internacional sin `+`.
     * @param string $text Texto a enviar.
     * @return string|null Identificador del mensaje en el proveedor, si lo informa.
     *
     * @throws NotificationException Si el proveedor rechaza o no responde.
     */
    public function sendText(array $account, string $phone, string $text): ?string;
}
