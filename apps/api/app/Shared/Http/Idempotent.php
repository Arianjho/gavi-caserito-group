<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hace idempotentes las operaciones que escriben datos.
 *
 * Si la petición trae `Idempotency-Key`, la primera ejecución guarda su
 * resultado y los reintentos con la misma llave devuelven ese resultado sin
 * volver a ejecutar. Reutilizar la llave con otro cuerpo es un error.
 */
final class Idempotent
{
    /**
     * Métodos que modifican datos.
     *
     * @var list<string>
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Procesa la petición aplicando la llave de idempotencia.
     *
     * @param Request $request Petición entrante.
     * @param Closure(Request): Response $next Siguiente capa.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $tenantId = $request->user()?->tenant_id;

        if ($key === null || $tenantId === null || ! in_array($request->method(), self::WRITE_METHODS, true)) {
            return $next($request);
        }

        $scope = $request->method().' '.$request->path();
        $hash = hash('sha256', (string) $request->getContent());

        $existing = DB::table('core.idempotency_keys')
            ->where('scope', $scope)->where('key', $key)->first();

        if ($existing !== null) {
            return $this->replay((array) $existing, $hash);
        }

        $id = (string) Str::uuid();
        $claimed = DB::table('core.idempotency_keys')->insertOrIgnore([
            'id' => $id, 'tenant_id' => $tenantId, 'key' => $key, 'scope' => $scope, 'request_hash' => $hash,
        ]);

        if ($claimed === 0) {
            return $this->inProgress();
        }

        $response = $next($request);

        if ($response->getStatusCode() >= 500) {
            DB::table('core.idempotency_keys')->where('id', $id)->delete();

            return $response;
        }

        DB::table('core.idempotency_keys')->where('id', $id)->update([
            'status' => 'done',
            'response_status' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
        ]);

        return $response;
    }

    /**
     * Responde con el resultado guardado de una ejecución anterior.
     *
     * @param array<string, mixed> $row Fila de `core.idempotency_keys`.
     * @param string $hash Huella de la petición actual.
     */
    private function replay(array $row, string $hash): Response
    {
        if ($row['request_hash'] !== $hash) {
            return response()->json([
                'code' => 'idempotency_key_reused',
                'message' => 'Esta llave ya se usó con otros datos.',
            ], 422);
        }

        if ($row['status'] !== 'done') {
            return $this->inProgress();
        }

        return response((string) $row['response_body'], (int) $row['response_status'])
            ->header('Content-Type', 'application/json')
            ->header('Idempotent-Replay', 'true');
    }

    /**
     * Respuesta para una operación idéntica que aún se está ejecutando.
     */
    private function inProgress(): Response
    {
        return response()->json([
            'code' => 'request_in_progress',
            'message' => 'La operación ya se está procesando. Espera un momento.',
        ], 409);
    }
}
