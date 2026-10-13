<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador de Evolution API para enviar texto por WhatsApp.
 */
final class EvolutionApiNotifier implements Notifier
{
    /**
     * Envía un texto a un teléfono mediante la instancia del negocio.
     *
     * @param array{base_url: string, instance: string, api_key: string} $account Conexión del negocio.
     * @param string $phone Teléfono en formato internacional sin `+`.
     * @param string $text Texto a enviar.
     */
    public function sendText(array $account, string $phone, string $text): ?string
    {
        $url = rtrim($account['base_url'], '/').'/message/sendText/'.rawurlencode($account['instance']);

        try {
            $response = Http::withHeaders(['apikey' => $account['api_key']])
                ->acceptJson()->asJson()->connectTimeout(5)->timeout(20)
                ->post($url, ['number' => $phone, 'text' => $text]);
        } catch (ConnectionException $e) {
            throw new NotificationException('Sin conexión con WhatsApp: '.$e->getMessage(), true);
        }

        $status = $response->status();
        if ($status === 429 || $status >= 500) {
            throw new NotificationException("WhatsApp no disponible ({$status}).", true);
        }
        if ($response->failed()) {
            throw new NotificationException('WhatsApp rechazó el mensaje (código '.$status.').', false);
        }

        $id = $response->json('key.id');

        return is_string($id) ? $id : null;
    }
}
