<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Envío y consulta a eFact de los documentos que no son factura ni boleta:
 * notas de crédito (`note`) y guías de remisión (`guide`).
 *
 * Misma política que los comprobantes: nunca lanza, devuelve si conviene
 * reintentar y deja el último error en la fila.
 */
final class FiscalRecordSync
{
    /**
     * Tabla local y recurso de eFact por tipo de documento.
     *
     * @var array<string, array{table: string, resource: string}>
     */
    public const KINDS = [
        'note' => ['table' => 'fiscal.credit_notes', 'resource' => 'notas-credito'],
        'guide' => ['table' => 'fiscal.dispatch_guides', 'resource' => 'guias-remision'],
    ];

    /**
     * Crea el servicio.
     *
     * @param FiscalEngine $engine Motor de facturación.
     */
    public function __construct(private readonly FiscalEngine $engine) {}

    /**
     * Envía el documento a eFact y registra el resultado.
     *
     * @param string $kind `note` o `guide`.
     * @param string $id Documento local.
     * @return bool `true` si hay que reintentar con espera.
     */
    public function send(string $kind, string $id): bool
    {
        ['table' => $table, 'resource' => $resource] = self::KINDS[$kind];
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null || in_array($row->status, ['accepted', 'rejected'], true)) {
            return false;
        }

        try {
            $result = $this->engine->submit(
                EfactCredentials::forCompany((string) $row->company_id),
                $resource,
                $this->payload($kind, $row),
            );
        } catch (FiscalException $e) {
            DB::table($table)->where('id', $id)->update([
                'attempts' => $row->attempts + 1, 'last_error' => $e->getMessage(),
                'status' => $e->retryable ? $row->status : 'error', 'updated_at' => now(),
            ]);

            return $e->retryable;
        }

        DB::table($table)->where('id', $id)->update([
            'attempts' => $row->attempts + 1, 'efact_id' => $result['efact_id'], 'number' => $result['number'],
            'sent_at' => $row->sent_at ?? now(), 'last_error' => null, 'updated_at' => now(),
        ]);
        $this->apply($kind, $id, $result['status'], $result['code'], $result['description']);

        return false;
    }

    /**
     * Consulta a eFact un documento sin estado final.
     *
     * @param string $kind `note` o `guide`.
     * @param string $id Documento local.
     */
    public function refresh(string $kind, string $id): void
    {
        ['table' => $table, 'resource' => $resource] = self::KINDS[$kind];
        $row = DB::table($table)->where('id', $id)->first();
        if ($row === null || $row->efact_id === null || in_array($row->status, ['accepted', 'rejected'], true)) {
            return;
        }
        try {
            $result = $this->engine->query(EfactCredentials::forCompany((string) $row->company_id), $resource, (string) $row->efact_id);
        } catch (FiscalException) {
            return;
        }
        $this->apply($kind, $id, $result['status'], $result['code'], $result['description']);
    }

    /**
     * Aplica un estado de eFact (respuesta, webhook o consulta).
     *
     * @param string $kind `note` o `guide`.
     * @param string $id Documento local.
     * @param string $efactStatus Estado de eFact.
     * @param string|null $code Código SUNAT.
     * @param string|null $description Descripción SUNAT.
     */
    public function apply(string $kind, string $id, string $efactStatus, ?string $code, ?string $description): void
    {
        $status = PayloadParts::status($efactStatus);
        $changes = ['status' => $status, 'sunat_code' => $code, 'sunat_description' => $description, 'updated_at' => now()];
        if ($status !== 'pending') {
            $changes['finished_at'] = now();
        }
        DB::table(self::KINDS[$kind]['table'])->where('id', $id)->update($changes);
    }

    /**
     * Arma el cuerpo según el tipo de documento.
     *
     * @param string $kind `note` o `guide`.
     * @param stdClass $row Fila local.
     * @return array<string, mixed>
     */
    private function payload(string $kind, stdClass $row): array
    {
        return $kind === 'note'
            ? app(CreditNoteService::class)->payload($row)
            : app(DispatchGuideService::class)->payload($row);
    }
}
