<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use stdClass;

/**
 * Conexión de cada empresa con Gavi eFact. Los secretos se guardan cifrados
 * y nunca se devuelven.
 */
class FiscalSettingsController extends Controller
{
    /**
     * Muestra la configuración (sin secretos) de una empresa.
     *
     * @param string $companyId Empresa.
     */
    public function show(string $companyId): JsonResponse
    {
        $settings = DB::table('fiscal.efact_settings')->where('company_id', $companyId)->first();

        return response()->json(['data' => $settings === null ? null : $this->present($settings)]);
    }

    /**
     * Crea o actualiza la configuración de la empresa.
     *
     * @param Request $request Datos de conexión.
     * @param string $companyId Empresa.
     */
    public function update(Request $request, string $companyId): JsonResponse
    {
        Company::query()->findOrFail($companyId);
        $existing = DB::table('fiscal.efact_settings')->where('company_id', $companyId)->first();

        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:190'],
            'environment' => ['required', Rule::in(['beta', 'production'])],
            'api_key' => [$existing === null ? 'required' : 'nullable', 'string', 'max:200'],
            'api_secret' => [$existing === null ? 'required' : 'nullable', 'string', 'max:200'],
            'webhook_secret' => ['nullable', 'string', 'max:200'],
            'boleta_series' => ['required', 'string', 'size:4'],
            'invoice_series' => ['required', 'string', 'size:4'],
            'credit_invoice_series' => ['sometimes', 'string', 'size:4'],
            'credit_boleta_series' => ['sometimes', 'string', 'size:4'],
            'dispatch_series' => ['sometimes', 'string', 'size:4'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $values = [
            'base_url' => $data['base_url'], 'environment' => $data['environment'],
            'boleta_series' => strtoupper($data['boleta_series']), 'invoice_series' => strtoupper($data['invoice_series']),
            'enabled' => $data['enabled'] ?? true, 'updated_at' => now(),
            'updated_by' => $request->user()?->getAuthIdentifier(),
        ];
        $values += $this->extraSeries($data);
        foreach (['api_key', 'api_secret', 'webhook_secret'] as $secret) {
            if (! empty($data[$secret])) {
                $values[$secret] = Crypt::encryptString($data[$secret]);
            }
        }

        if ($existing === null) {
            DB::table('fiscal.efact_settings')->insert($values + [
                'id' => (string) Str::uuid(), 'tenant_id' => $request->user()?->tenant_id, 'company_id' => $companyId,
                'created_by' => $request->user()?->getAuthIdentifier(),
            ]);
        } else {
            DB::table('fiscal.efact_settings')->where('company_id', $companyId)->update($values);
        }

        return $this->show($companyId);
    }

    /**
     * Series opcionales de notas de crédito y guías, en mayúsculas.
     *
     * @param array<string, mixed> $data Datos validados.
     * @return array<string, string>
     */
    private function extraSeries(array $data): array
    {
        $series = [];
        foreach (['credit_invoice_series', 'credit_boleta_series', 'dispatch_series'] as $key) {
            if (isset($data[$key])) {
                $series[$key] = strtoupper((string) $data[$key]);
            }
        }

        return $series;
    }

    /**
     * Presenta la configuración sin exponer secretos.
     *
     * @param stdClass $settings Fila de configuración.
     * @return array<string, mixed>
     */
    private function present(stdClass $settings): array
    {
        return [
            'company_id' => $settings->company_id, 'base_url' => $settings->base_url,
            'environment' => $settings->environment, 'boleta_series' => $settings->boleta_series,
            'invoice_series' => $settings->invoice_series, 'enabled' => (bool) $settings->enabled,
            'credit_invoice_series' => $settings->credit_invoice_series,
            'credit_boleta_series' => $settings->credit_boleta_series, 'dispatch_series' => $settings->dispatch_series,
            'has_credentials' => $settings->api_key !== null && $settings->api_secret !== null,
            'has_webhook_secret' => $settings->webhook_secret !== null,
        ];
    }
}
