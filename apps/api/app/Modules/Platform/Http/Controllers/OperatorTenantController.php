<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\ProvisionAccount;
use App\Modules\Platform\Models\User;
use App\Modules\Sales\Application\SaleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Administración de cuentas desde el panel SaaS: listado con uso, alta,
 * y cambio de plan, límites y estado (suspender o reactivar).
 */
class OperatorTenantController extends Controller
{
    /** Columnas del resumen de una cuenta, con su uso. */
    private const COLUMNS = 't.id, t.name, t.plan_code, t.plan_status, t.trial_ends_at, t.max_users, t.max_companies, '
        .'t.max_documents_month, t.created_at, '
        ."(select count(*) from core.users u where u.tenant_id = t.id and u.status = 'active') as users_used, "
        ."(select count(*) from core.companies c where c.tenant_id = t.id and c.status = 'active') as companies_used, "
        .'(select count(*) from sales.sales_documents d where d.tenant_id = t.id '
        ."and d.created_at >= date_trunc('month', now())) as documents_month, "
        .'(select max(u.last_login_at) from core.users u where u.tenant_id = t.id) as last_login_at';

    /**
     * Lista las cuentas con su uso actual.
     *
     * @param Request $request Admite `q` por nombre.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('core.tenants as t')->orderByDesc('t.created_at')->limit(300);
        if ($request->filled('q')) {
            $query->whereRaw('lower(t.name) like ?', ['%'.mb_strtolower((string) $request->query('q')).'%']);
        }
        $rows = $query->selectRaw(self::COLUMNS)->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * Muestra una cuenta con su uso y propietarios.
     *
     * @param string $id Cuenta.
     */
    public function show(string $id): JsonResponse
    {
        $tenant = DB::table('core.tenants as t')->where('t.id', $id)->selectRaw(self::COLUMNS)->first();
        if ($tenant === null) {
            throw new SaleException('not_found', 'Cuenta no encontrada.', 404);
        }
        $tenant->owners = DB::table('core.users')->where('tenant_id', $id)->where('is_owner', true)
            ->get(['name', 'email', 'status', 'last_login_at']);

        return response()->json(['data' => $tenant]);
    }

    /**
     * Crea una cuenta nueva con su propietario (14 días de prueba).
     *
     * @param Request $request Datos de la cuenta y del propietario.
     * @param ProvisionAccount $provision Servicio de alta.
     */
    public function store(Request $request, ProvisionAccount $provision): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_email' => ['required', 'email', 'max:190', Rule::unique(User::class, 'email')->whereNot('status', 'deleted')],
            'owner_password' => ['required', 'string', 'min:10', 'max:100'],
        ]);

        $result = $provision->handle($data['name'], $data['owner_name'], $data['owner_email'], $data['owner_password']);
        $this->audit($request, (string) $result['tenant']->id, 'operator_account_created', ['name' => $data['name']]);

        return $this->show((string) $result['tenant']->id)->setStatusCode(201);
    }

    /**
     * Cambia plan, estado, fin de prueba y límites de una cuenta.
     *
     * @param Request $request Campos a cambiar; `null` en un tope significa sin tope.
     * @param string $id Cuenta.
     */
    public function updatePlan(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'plan_code' => ['sometimes', 'string', 'max:30'],
            'plan_status' => ['sometimes', Rule::in(['trial', 'active', 'suspended', 'closed'])],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'max_companies' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'max_documents_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
        ]);
        $before = DB::table('core.tenants')->where('id', $id)->first();
        if ($before === null) {
            throw new SaleException('not_found', 'Cuenta no encontrada.', 404);
        }

        DB::table('core.tenants')->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->audit($request, $id, 'operator_plan_updated', [
            'before' => array_intersect_key((array) $before, $data), 'after' => $data,
        ]);

        return $this->show($id);
    }

    /**
     * Registra la acción del operador en la bitácora inmutable.
     *
     * @param Request $request Petición del operador.
     * @param string $tenantId Cuenta afectada.
     * @param string $action Acción realizada.
     * @param array<string, mixed> $changes Detalle del cambio.
     */
    private function audit(Request $request, string $tenantId, string $action, array $changes): void
    {
        DB::table('core.audit_log')->insert([
            'tenant_id' => $tenantId, 'actor_id' => $request->user()?->getAuthIdentifier(),
            'action' => $action, 'entity_type' => 'core.tenants', 'entity_id' => $tenantId,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR), 'ip_address' => $request->ip(),
        ]);
    }
}
