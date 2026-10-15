<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Sales\Application\SaleException;
use App\Modules\Sales\Application\SaleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Baja de comprobantes aceptados por SUNAT.
 *
 * Facturas: comunicación de baja (`anulaciones`). Boletas: resumen diario en
 * modo anulación (`resumenes`). Ambas dentro de los 7 días calendario y solo
 * si el comprobante no tiene notas de crédito. Al aceptarse la baja, la venta
 * se anula y el stock vuelve al almacén.
 */
final class FiscalVoidService
{
    /**
     * Plazo de baja en días calendario desde la emisión.
     */
    private const WINDOW_DAYS = 7;

    /**
     * Crea el servicio.
     *
     * @param FiscalEngine $engine Motor de facturación.
     */
    public function __construct(private readonly FiscalEngine $engine) {}

    /**
     * Registra la solicitud de baja, pendiente de envío.
     *
     * @param string $documentId Comprobante.
     * @param string $reason Motivo (máx. 255).
     * @return array{id: string, tenant_id: string}
     *
     * @throws SaleException Si no es elegible para baja.
     */
    public function request(string $documentId, string $reason): array
    {
        return DB::transaction(function () use ($documentId, $reason): array {
            $doc = DB::table('fiscal.documents')->where('id', $documentId)->lockForUpdate()->first();
            $this->assertVoidable($doc);
            /** @var stdClass $doc */
            DB::table('fiscal.documents')->where('id', $documentId)->update([
                'void_status' => 'pending', 'void_reason' => $reason, 'void_efact_id' => null, 'void_error' => null,
                'void_requested_at' => now(), 'updated_by' => request()->user()?->getAuthIdentifier(), 'updated_at' => now(),
            ]);

            return ['id' => $documentId, 'tenant_id' => (string) $doc->tenant_id];
        });
    }

    /**
     * Envía la baja a eFact.
     *
     * @param string $documentId Comprobante.
     * @return bool `true` si hay que reintentar con espera.
     */
    public function send(string $documentId): bool
    {
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->first();
        if ($doc === null || $doc->void_status !== 'pending' || $doc->void_efact_id !== null) {
            return false;
        }
        $resource = $doc->cpe_type === '01' ? 'anulaciones' : 'resumenes';

        try {
            $result = $this->engine->submit(EfactCredentials::forCompany((string) $doc->company_id), $resource, $this->payload($doc));
        } catch (FiscalException $e) {
            if (! $e->retryable) {
                DB::table('fiscal.documents')->where('id', $documentId)->update([
                    'void_status' => 'rejected', 'void_error' => $e->getMessage(), 'updated_at' => now(),
                ]);
            }

            return $e->retryable;
        }

        DB::table('fiscal.documents')->where('id', $documentId)->update(['void_efact_id' => $result['efact_id'], 'updated_at' => now()]);
        $this->apply($documentId, $result['status'], $result['description']);

        return false;
    }

    /**
     * Consulta a eFact una baja en curso.
     *
     * @param string $documentId Comprobante.
     */
    public function refresh(string $documentId): void
    {
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->first();
        if ($doc === null || $doc->void_status !== 'pending' || $doc->void_efact_id === null) {
            return;
        }
        $resource = $doc->cpe_type === '01' ? 'anulaciones' : 'resumenes';
        try {
            $result = $this->engine->query(EfactCredentials::forCompany((string) $doc->company_id), $resource, (string) $doc->void_efact_id);
        } catch (FiscalException) {
            return;
        }
        $this->apply($documentId, $result['status'], $result['description']);
    }

    /**
     * Valida que el comprobante pueda darse de baja.
     *
     * @param stdClass|null $doc Comprobante bloqueado.
     *
     * @throws SaleException Si no existe, no está aceptado, venció el plazo o tiene notas.
     */
    private function assertVoidable(?stdClass $doc): void
    {
        if ($doc === null) {
            throw new SaleException('not_found', 'Comprobante no encontrado.', 404);
        }
        if ($doc->status !== 'accepted') {
            throw new SaleException('document_not_accepted', 'Solo un comprobante aceptado por SUNAT admite baja.', 409);
        }
        if ($doc->void_status === 'pending') {
            throw new SaleException('void_in_progress', 'La baja ya está en curso.', 409);
        }
        $issued = DB::table('sales.sales_documents')->where('id', $doc->sales_document_id)->value('issued_at');
        if (Carbon::parse((string) $issued)->startOfDay()->diffInDays(now()->startOfDay()) > self::WINDOW_DAYS) {
            throw new SaleException('void_window_expired', 'Pasaron más de 7 días: corrígelo con nota de crédito.', 422);
        }
        $hasNote = DB::table('fiscal.credit_notes')->where('document_id', $doc->id)
            ->whereIn('status', ['queued', 'pending', 'accepted'])->exists();
        if ($hasNote) {
            throw new SaleException('has_credit_note', 'El comprobante tiene notas de crédito; no admite baja.', 409);
        }
    }

    /**
     * Cuerpo de la baja según el tipo de comprobante.
     *
     * @param stdClass $doc Comprobante.
     * @return array<string, mixed>
     */
    private function payload(stdClass $doc): array
    {
        $issued = substr((string) DB::table('sales.sales_documents')->where('id', $doc->sales_document_id)->value('issued_at'), 0, 10);
        if ($doc->cpe_type === '03') {
            return [
                'fecha_resumen' => now()->toDateString(),
                'anular' => [[
                    'id' => is_numeric($doc->efact_id) ? (int) $doc->efact_id : $doc->efact_id,
                    'motivo' => $doc->void_reason, 'tipo_documento' => '03',
                ]],
            ];
        }

        return [
            'fecha_generacion' => $issued, 'fecha_comunicacion' => now()->toDateString(),
            'detalles' => [[
                'tipo_documento' => '01', 'serie' => $doc->series, 'correlativo' => (string) $doc->number,
                'motivo' => $doc->void_reason,
            ]],
        ];
    }

    /**
     * Aplica el estado de la baja; al aceptarse, anula la venta de origen.
     *
     * @param string $documentId Comprobante.
     * @param string $efactStatus Estado de eFact.
     * @param string|null $description Descripción SUNAT.
     */
    private function apply(string $documentId, string $efactStatus, ?string $description): void
    {
        $status = PayloadParts::status($efactStatus);
        if ($status === 'pending') {
            return;
        }
        if ($status === 'rejected') {
            DB::table('fiscal.documents')->where('id', $documentId)->update([
                'void_status' => 'rejected', 'void_error' => $description, 'updated_at' => now(),
            ]);

            return;
        }
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->first();
        DB::table('fiscal.documents')->where('id', $documentId)->update([
            'status' => 'voided', 'void_status' => 'accepted', 'voided_at' => now(), 'void_error' => null, 'updated_at' => now(),
        ]);
        $this->voidSale((string) $doc?->sales_document_id, (string) $doc?->void_reason, $documentId);
    }

    /**
     * Anula la venta y devuelve el stock; si no se puede, lo deja anotado.
     *
     * @param string $saleId Venta.
     * @param string $reason Motivo de la baja.
     * @param string $documentId Comprobante donde anotar un problema.
     */
    private function voidSale(string $saleId, string $reason, string $documentId): void
    {
        try {
            app(SaleService::class)->void($saleId, 'Baja del comprobante electrónico: '.$reason);
        } catch (SaleException $e) {
            DB::table('fiscal.documents')->where('id', $documentId)->update([
                'void_error' => 'Baja aceptada, pero no se pudo anular la venta: '.$e->getMessage(),
            ]);
        }
    }
}
