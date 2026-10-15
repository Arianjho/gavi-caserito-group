<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

/**
 * Puerto del motor de facturación electrónica.
 *
 * El dominio solo conoce esta interfaz; el adaptador de Gavi eFact (u otro
 * proveedor) vive detrás de ella.
 */
interface FiscalEngine
{
    /**
     * Crea (o recupera, de forma idempotente) un comprobante en el proveedor.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión de la empresa.
     * @param string $cpeType `01` factura o `03` boleta.
     * @param array<string, mixed> $payload Cuerpo del comprobante (incluye `external_id`).
     * @return array{efact_id: string, series: string|null, number: string|null, status: string, code: string|null, description: string|null}
     *
     * @throws FiscalException Si el proveedor rechaza o no responde.
     */
    public function emit(array $credentials, string $cpeType, array $payload): array;

    /**
     * Consulta el estado actual de un comprobante.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión de la empresa.
     * @param string $cpeType `01` factura o `03` boleta.
     * @param string $efactId Identificador en el proveedor.
     * @return array{status: string, code: string|null, description: string|null}
     *
     * @throws FiscalException Si el proveedor no responde.
     */
    public function status(array $credentials, string $cpeType, string $efactId): array;

    /**
     * Envía un documento de otro recurso (nota de crédito, baja, resumen o guía).
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión de la empresa.
     * @param string $resource Recurso: `notas-credito`, `anulaciones`, `resumenes` o `guias-remision`.
     * @param array<string, mixed> $payload Cuerpo del documento.
     * @return array{efact_id: string, series: string|null, number: string|null, status: string, code: string|null, description: string|null}
     *
     * @throws FiscalException Si el proveedor rechaza o no responde.
     */
    public function submit(array $credentials, string $resource, array $payload): array;

    /**
     * Consulta el estado de un documento de otro recurso.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión de la empresa.
     * @param string $resource Recurso: `notas-credito`, `anulaciones`, `resumenes` o `guias-remision`.
     * @param string $efactId Identificador en el proveedor.
     * @return array{status: string, code: string|null, description: string|null}
     *
     * @throws FiscalException Si el proveedor no responde.
     */
    public function query(array $credentials, string $resource, string $efactId): array;
}
