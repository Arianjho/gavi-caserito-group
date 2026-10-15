<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Sales\Application\SaleException;
use Illuminate\Support\Facades\DB;

/**
 * Consulta el uso de la cuenta frente a los límites de su plan.
 */
final class PlanLimits
{
    private const COUNTS = [
        'users' => ['core.users', 'max_users', 'usuarios'],
        'companies' => ['core.companies', 'max_companies', 'empresas'],
    ];

    /**
     * Falla si crear un registro más superaría el tope del plan.
     *
     * @param string $tenantId Cuenta.
     * @param string $resource `users` o `companies`.
     *
     * @throws SaleException Con código `plan_limit_reached`.
     */
    public function ensureCanCreate(string $tenantId, string $resource): void
    {
        [$table, $column, $label] = self::COUNTS[$resource];
        $max = DB::table('core.tenants')->where('id', $tenantId)->value($column);
        if ($max === null) {
            return;
        }
        if ($this->active($table, $tenantId) >= (int) $max) {
            throw new SaleException('plan_limit_reached', "Tu plan permite hasta {$max} {$label}. Amplía tu plan para agregar más.", 403);
        }
    }

    /**
     * Falla si emitir un comprobante electrónico más superaría el cupo mensual del plan.
     *
     * La venta ya registrada no se pierde: queda como nota de venta y se puede
     * emitir al ampliar el plan o al iniciar el mes siguiente.
     *
     * @param string $tenantId Cuenta.
     *
     * @throws SaleException Con código `document_quota_reached`.
     */
    public function ensureCanEmit(string $tenantId): void
    {
        $max = DB::table('core.tenants')->where('id', $tenantId)->value('max_documents_month');
        if ($max === null) {
            return;
        }
        if ($this->monthlyDocuments($tenantId) >= (int) $max) {
            throw new SaleException(
                'document_quota_reached',
                "Llegaste al cupo de {$max} comprobantes electrónicos de tu plan este mes. La venta queda como nota de venta; amplía tu plan o emítela el mes siguiente.",
                403,
            );
        }
    }

    /**
     * Comprobantes electrónicos (facturas, boletas y notas de crédito) del mes en curso.
     *
     * Los rechazados por SUNAT no consumen cupo.
     *
     * @param string $tenantId Cuenta.
     */
    public function monthlyDocuments(string $tenantId): int
    {
        $start = now()->startOfMonth();
        $documents = DB::table('fiscal.documents')->where('tenant_id', $tenantId)
            ->where('status', '<>', 'rejected')->where('created_at', '>=', $start)->count();
        $notes = DB::table('fiscal.credit_notes')->where('tenant_id', $tenantId)
            ->where('status', '<>', 'rejected')->where('created_at', '>=', $start)->count();

        return $documents + $notes;
    }

    /**
     * Plan, límites y uso actual de la cuenta.
     *
     * @param string $tenantId Cuenta.
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $tenant = DB::table('core.tenants')->where('id', $tenantId)
            ->first(['plan_code', 'plan_status', 'trial_ends_at', 'max_users', 'max_companies', 'max_documents_month']);
        $documents = $this->monthlyDocuments($tenantId);

        return [
            'plan_code' => $tenant->plan_code ?? null,
            'plan_status' => $tenant->plan_status ?? null,
            'trial_ends_at' => $tenant->trial_ends_at ?? null,
            'limits' => [
                'users' => ['max' => $tenant->max_users ?? null, 'used' => $this->active('core.users', $tenantId)],
                'companies' => ['max' => $tenant->max_companies ?? null, 'used' => $this->active('core.companies', $tenantId)],
                'documents_month' => [
                    'max' => $tenant->max_documents_month ?? null,
                    'used' => $documents,
                    'level' => $this->level($documents, $tenant->max_documents_month ?? null),
                ],
            ],
        ];
    }

    /**
     * Nivel de consumo del cupo: `ok`, `warning` desde el 80 % y `reached` al llegar al tope.
     *
     * @param int $used Comprobantes del mes.
     * @param int|string|null $max Tope del plan o `null` si no hay.
     */
    private function level(int $used, int|string|null $max): string
    {
        if ($max === null || (int) $max <= 0) {
            return 'ok';
        }

        return match (true) {
            $used >= (int) $max => 'reached',
            $used * 100 >= (int) $max * 80 => 'warning',
            default => 'ok',
        };
    }

    /**
     * Cuenta registros activos de una tabla.
     *
     * @param string $table Tabla.
     * @param string $tenantId Cuenta.
     */
    private function active(string $table, string $tenantId): int
    {
        return DB::table($table)->where('tenant_id', $tenantId)->where('status', 'active')->count();
    }
}
