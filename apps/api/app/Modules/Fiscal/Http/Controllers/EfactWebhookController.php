<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\FiscalService;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Receptor público de webhooks de Gavi eFact.
 *
 * Verifica la firma HMAC sobre el cuerpo crudo, rechaza marcas de tiempo
 * viejas y deduplica por `X-Gavi-Event-Id`.
 */
class EfactWebhookController extends Controller
{
    /**
     * Procesa un evento de eFact.
     *
     * @param Request $request Petición firmada.
     * @param string $companyId Empresa a la que pertenece el webhook.
     * @param FiscalService $fiscal Servicio fiscal.
     */
    public function __invoke(Request $request, string $companyId, FiscalService $fiscal): JsonResponse
    {
        return TenantContext::asProvider(function () use ($request, $companyId, $fiscal): JsonResponse {
            $settings = DB::table('fiscal.efact_settings')->where('company_id', $companyId)->first();
            if ($settings === null || $settings->webhook_secret === null || ! $this->validSignature($request, $settings)) {
                return response()->json(['code' => 'invalid_signature'], 401);
            }

            $eventId = (string) $request->header('X-Gavi-Event-Id', '');
            $payload = $request->json()->all();
            if ($eventId === '' || DB::table('fiscal.webhook_events')->where('event_id', $eventId)->exists()) {
                return response()->json(['data' => ['duplicate' => true]]);
            }

            DB::transaction(function () use ($settings, $eventId, $payload, $fiscal): void {
                DB::table('fiscal.webhook_events')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $settings->tenant_id, 'company_id' => $settings->company_id,
                    'event_id' => $eventId, 'event' => (string) ($payload['event'] ?? 'unknown'),
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]);
                $this->apply($payload, (string) $settings->company_id, $fiscal);
            });

            return response()->json(['data' => ['ok' => true]]);
        });
    }

    /**
     * Valida firma y antigüedad del evento.
     *
     * @param Request $request Petición recibida.
     * @param stdClass $settings Configuración de la empresa.
     */
    private function validSignature(Request $request, stdClass $settings): bool
    {
        $timestamp = (string) $request->header('X-Gavi-Timestamp', '');
        $signature = (string) $request->header('X-Gavi-Signature', '');
        try {
            $secret = Crypt::decryptString((string) $settings->webhook_secret);
        } catch (Throwable) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature) && abs(time() - (int) $timestamp) <= 300;
    }

    /**
     * Aplica el estado del evento al comprobante local.
     *
     * @param array<string, mixed> $payload Cuerpo del evento.
     * @param string $companyId Empresa.
     * @param FiscalService $fiscal Servicio fiscal.
     */
    private function apply(array $payload, string $companyId, FiscalService $fiscal): void
    {
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $efactId = isset($document['id']) ? (string) $document['id'] : '';
        if ($efactId === '') {
            return;
        }
        $local = DB::table('fiscal.documents')->where('company_id', $companyId)->where('efact_id', $efactId)->first();
        if ($local === null) {
            return;
        }
        $fiscal->applyStatus(
            (string) $local->id,
            (string) ($document['sunat_status'] ?? 'pendiente'),
            isset($document['sunat_code']) ? (string) $document['sunat_code'] : null,
            isset($document['sunat_description']) ? (string) $document['sunat_description'] : null,
        );
    }
}
