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
     * Plan, límites y uso actual de la cuenta.
     *
     * @param string $tenantId Cuenta.
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $tenant = DB::table('core.tenants')->where('id', $tenantId)
            ->first(['plan_code', 'plan_status', 'trial_ends_at', 'max_users', 'max_companies', 'max_documents_month']);
        $documents = DB::table('sales.sales_documents')->where('tenant_id', $tenantId)
            ->whereRaw("created_at >= date_trunc('month', now())")->count();

        return [
            'plan_code' => $tenant->plan_code ?? null,
            'plan_status' => $tenant->plan_status ?? null,
            'trial_ends_at' => $tenant->trial_ends_at ?? null,
            'limits' => [
                'users' => ['max' => $tenant->max_users ?? null, 'used' => $this->active('core.users', $tenantId)],
                'companies' => ['max' => $tenant->max_companies ?? null, 'used' => $this->active('core.companies', $tenantId)],
                'documents_month' => ['max' => $tenant->max_documents_month ?? null, 'used' => $documents],
            ],
        ];
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
