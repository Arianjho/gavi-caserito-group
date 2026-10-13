<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Sales\Application\SaleException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Encola y envía mensajes de WhatsApp a clientes que dieron su
 * consentimiento. Un corte del canal nunca afecta a la operación de origen.
 */
final class NotificationService
{
    /**
     * Crea el servicio.
     *
     * @param Notifier $notifier Canal de envío.
     */
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * Registra un mensaje pendiente para un cliente.
     *
     * @param string $tenantId Cuenta.
     * @param string $event Evento que lo origina.
     * @param string $partyId Cliente destinatario.
     * @param string $body Texto del mensaje.
     * @param string|null $refType Documento de origen.
     * @param string|null $refId Identificador del documento de origen.
     * @return string Identificador del mensaje encolado.
     *
     * @throws SaleException Si falta canal, consentimiento o teléfono válido.
     */
    public function queueForParty(string $tenantId, string $event, string $partyId, string $body, ?string $refType = null, ?string $refId = null): string
    {
        $account = DB::table('notif.channel_accounts')->where('tenant_id', $tenantId)->first();
        if ($account === null || ! $account->enabled) {
            throw new SaleException('channel_not_configured', 'Configura y activa WhatsApp en Notificaciones.', 422);
        }
        $party = DB::table('cat.parties')->where('id', $partyId)->first();
        if ($party === null) {
            throw new SaleException('not_found', 'Cliente no encontrado.', 404);
        }
        if (! $party->whatsapp_opt_in) {
            throw new SaleException('no_opt_in', 'El cliente no aceptó recibir mensajes de WhatsApp.', 422);
        }
        $phone = $this->normalizePhone((string) $party->phone);

        return $this->insert($tenantId, $event, $partyId, $phone, $body, $refType, $refId);
    }

    /**
     * Registra un mensaje de prueba a un teléfono libre.
     *
     * @param string $tenantId Cuenta.
     * @param string $phone Teléfono del destinatario.
     * @return string Identificador del mensaje.
     */
    public function queueTest(string $tenantId, string $phone): string
    {
        return $this->insert(
            $tenantId, 'test', null, $this->normalizePhone($phone),
            'Mensaje de prueba de Gavi Caserito. Si lo lees, WhatsApp está bien conectado.',
        );
    }

    /**
     * Envía un mensaje encolado. No lanza excepciones.
     *
     * @param string $messageId Mensaje.
     * @return bool `true` si conviene reintentar con espera.
     */
    public function send(string $messageId): bool
    {
        $message = DB::table('notif.messages')->where('id', $messageId)->first();
        if ($message === null || $message->status !== 'queued') {
            return false;
        }
        $account = DB::table('notif.channel_accounts')->where('tenant_id', $message->tenant_id)->first();
        if ($account === null) {
            $this->fail($messageId, $message->attempts, 'El canal ya no está configurado.', false);

            return false;
        }

        try {
            $providerId = $this->notifier->sendText([
                'base_url' => (string) $account->base_url, 'instance' => (string) $account->instance,
                'api_key' => Crypt::decryptString((string) $account->api_key),
            ], (string) $message->phone, (string) $message->body);
        } catch (NotificationException $e) {
            $this->fail($messageId, $message->attempts, $e->getMessage(), $e->retryable);

            return $e->retryable;
        }

        DB::table('notif.messages')->where('id', $messageId)->update([
            'status' => 'sent', 'attempts' => $message->attempts + 1, 'error' => null,
            'provider_message_id' => $providerId, 'sent_at' => now(),
        ]);

        return false;
    }

    /**
     * Normaliza un teléfono peruano a formato internacional sin `+`.
     *
     * @param string $raw Teléfono como lo escribió el usuario.
     *
     * @throws SaleException Si no es un celular peruano reconocible.
     */
    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 9 && $digits[0] === '9') {
            return '51'.$digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '519')) {
            return $digits;
        }

        throw new SaleException('invalid_phone', 'El teléfono debe ser un celular peruano de 9 dígitos.', 422);
    }

    /**
     * Inserta el mensaje pendiente.
     *
     * @param string $tenantId Cuenta.
     * @param string $event Evento.
     * @param string|null $partyId Cliente.
     * @param string $phone Teléfono normalizado.
     * @param string $body Texto.
     * @param string|null $refType Tipo de documento de origen.
     * @param string|null $refId Documento de origen.
     */
    private function insert(string $tenantId, string $event, ?string $partyId, string $phone, string $body, ?string $refType = null, ?string $refId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('notif.messages')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'event' => $event, 'party_id' => $partyId,
            'phone' => $phone, 'body' => $body, 'ref_type' => $refType, 'ref_id' => $refId,
            'created_by' => request()->user()?->getAuthIdentifier(),
        ]);

        return $id;
    }

    /**
     * Registra un envío fallido; si no se reintenta, queda en `failed`.
     *
     * @param string $messageId Mensaje.
     * @param int $attempts Intentos previos.
     * @param string $error Descripción.
     * @param bool $retryable Si habrá otro intento.
     */
    private function fail(string $messageId, int $attempts, string $error, bool $retryable): void
    {
        DB::table('notif.messages')->where('id', $messageId)->update([
            'attempts' => $attempts + 1, 'error' => $error, 'status' => $retryable ? 'queued' : 'failed',
        ]);
    }
}
