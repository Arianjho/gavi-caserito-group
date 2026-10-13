<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador HTTP de Gavi eFact.
 *
 * Usa `external_id` para que repetir un envío nunca duplique el comprobante
 * y traduce los códigos HTTP a errores reintentables o definitivos.
 */
final class GaviEfactClient implements FiscalEngine
{
    /**
     * Rutas por tipo de comprobante.
     *
     * @var array<string, string>
     */
    private const PATHS = ['01' => 'facturas', '03' => 'boletas'];

    /**
     * Crea (o recupera) un comprobante.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión.
     * @param string $cpeType `01` o `03`.
     * @param array<string, mixed> $payload Cuerpo del comprobante.
     * @return array{efact_id: string, series: string|null, number: string|null, status: string, code: string|null, description: string|null}
     */
    public function emit(array $credentials, string $cpeType, array $payload): array
    {
        $response = $this->send(fn (): Response => $this->http($credentials)->post(self::PATHS[$cpeType], $payload));

        return $this->parse($response);
    }

    /**
     * Consulta el estado de un comprobante.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión.
     * @param string $cpeType `01` o `03`.
     * @param string $efactId Identificador en eFact.
     * @return array{status: string, code: string|null, description: string|null}
     */
    public function status(array $credentials, string $cpeType, string $efactId): array
    {
        $response = $this->send(fn (): Response => $this->http($credentials)->get(self::PATHS[$cpeType].'/'.$efactId));
        $parsed = $this->parse($response);

        return ['status' => $parsed['status'], 'code' => $parsed['code'], 'description' => $parsed['description']];
    }

    /**
     * Cliente HTTP con credenciales y tiempos de espera.
     *
     * @param array{base_url: string, api_key: string, api_secret: string} $credentials Conexión.
     */
    private function http(array $credentials): PendingRequest
    {
        return Http::baseUrl(rtrim($credentials['base_url'], '/').'/api/v1/')
            ->withHeaders(['X-Api-Key' => $credentials['api_key'], 'X-Api-Secret' => $credentials['api_secret']])
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }

    /**
     * Ejecuta la petición convirtiendo fallas de red en errores reintentables.
     *
     * @param callable(): Response $call Petición a ejecutar.
     *
     * @throws FiscalException Si no hay respuesta o el código HTTP es un error.
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new FiscalException('Sin conexión con eFact: '.$e->getMessage(), true);
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new FiscalException('eFact rechazó las credenciales o el plan: '.$this->message($response), false);
        }
        if ($status === 409 || $status === 422) {
            throw new FiscalException($this->message($response), false);
        }
        if ($status === 429 || $status >= 500) {
            throw new FiscalException('eFact no disponible ('.$status.').', true);
        }
        if ($response->failed()) {
            throw new FiscalException($this->message($response), false);
        }

        return $response;
    }

    /**
     * Extrae un mensaje legible de una respuesta de error.
     *
     * @param Response $response Respuesta HTTP.
     */
    private function message(Response $response): string
    {
        $json = $response->json();
        $message = is_array($json) && isset($json['mensaje']) && is_string($json['mensaje']) ? $json['mensaje'] : 'Error '.$response->status();
        $errors = is_array($json) && isset($json['errores']) && is_array($json['errores'])
            ? ' '.json_encode($json['errores'], JSON_UNESCAPED_UNICODE)
            : '';

        return $message.$errors;
    }

    /**
     * Normaliza la respuesta de un comprobante.
     *
     * @param Response $response Respuesta HTTP exitosa.
     * @return array{efact_id: string, series: string|null, number: string|null, status: string, code: string|null, description: string|null}
     */
    private function parse(Response $response): array
    {
        $data = $response->json('datos');
        if (! is_array($data)) {
            throw new FiscalException('Respuesta inesperada de eFact.', true);
        }
        $sunat = is_array($data['sunat'] ?? null) ? $data['sunat'] : [];

        return [
            'efact_id' => (string) ($data['id'] ?? ''),
            'series' => isset($data['serie']) ? (string) $data['serie'] : null,
            'number' => isset($data['correlativo']) ? (string) $data['correlativo'] : null,
            'status' => (string) ($sunat['estado'] ?? 'pendiente'),
            'code' => isset($sunat['codigo']) ? (string) $sunat['codigo'] : null,
            'description' => isset($sunat['descripcion']) ? (string) $sunat['descripcion'] : null,
        ];
    }
}
