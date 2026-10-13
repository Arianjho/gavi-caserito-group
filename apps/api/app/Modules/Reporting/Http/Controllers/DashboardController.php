<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Application\PermissionChecker;
use App\Modules\Platform\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del panel de inicio, limitados a lo que el usuario puede ver.
 */
class DashboardController extends Controller
{
    /**
     * Devuelve los indicadores del día y los pendientes.
     *
     * @param Request $request Petición autenticada.
     * @param PermissionChecker $checker Evaluador de permisos.
     */
    public function __invoke(Request $request, PermissionChecker $checker): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = [];

        if ($checker->can($user, 'sales.document.view')) {
            $data['sales_today'] = $this->salesToday();
            $data['top_products'] = $this->topProducts();
        }
        if ($checker->can($user, 'payments.receivable.view')) {
            $data['receivables'] = $this->receivables();
        }
        if ($checker->can($user, 'payments.payable.view')) {
            $data['payables'] = $this->payables();
        }
        if ($checker->can($user, 'orders.order.view')) {
            $data['active_orders'] = DB::table('sales.orders')
                ->whereIn('status', ['confirmed', 'in_dispatch', 'ready'])->count();
        }
        if ($checker->can($user, 'shipping.shipment.view')) {
            $data['open_shipments'] = DB::table('ship.shipments')
                ->whereNotIn('status', ['delivered', 'returned'])->count();
        }
        if ($checker->can($user, 'inventory.stock.view')) {
            $data['negative_stock'] = DB::table('inv.stock_balances')->where('on_hand', '<', 0)->count();
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Ventas confirmadas del día.
     *
     * @return array{count: int, total: string}
     */
    private function salesToday(): array
    {
        $row = DB::table('sales.sales_documents')
            ->where('status', 'confirmed')
            ->whereRaw('issued_at::date = current_date')
            ->selectRaw('count(*) as documents, coalesce(sum(total), 0) as total')->first();

        return [
            'count' => (int) ($row->documents ?? 0),
            'total' => number_format((float) ($row->total ?? 0), 2, '.', ''),
        ];
    }

    /**
     * Productos más vendidos de los últimos 30 días.
     *
     * @return array<int, array{name: string, qty: string, total: string}>
     */
    private function topProducts(): array
    {
        return DB::table('sales.sales_document_lines as l')
            ->join('sales.sales_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.status', 'confirmed')
            ->where('d.issued_at', '>=', now()->subDays(30))
            ->groupBy('l.description')
            ->orderByRaw('sum(l.total) desc')->limit(5)
            ->selectRaw('l.description as name, sum(l.qty) as qty, sum(l.total) as total')
            ->get()
            ->map(fn (object $r): array => [
                'name' => (string) $r->name,
                'qty' => number_format((float) $r->qty, 3, '.', ''),
                'total' => number_format((float) $r->total, 2, '.', ''),
            ])->values()->all();
    }

    /**
     * Total por cobrar y documentos vencidos.
     *
     * @return array{balance_total: string, overdue_count: int}
     */
    private function receivables(): array
    {
        $base = DB::table('sales.sales_documents')->where('status', 'confirmed')->where('balance', '>', 0);

        return [
            'balance_total' => number_format((float) (clone $base)->sum('balance'), 2, '.', ''),
            'overdue_count' => (clone $base)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count(),
        ];
    }

    /**
     * Total por pagar y compras vencidas.
     *
     * @return array{balance_total: string, overdue_count: int}
     */
    private function payables(): array
    {
        $base = DB::table('purch.purchase_documents')->where('status', 'confirmed')->where('balance', '>', 0);

        return [
            'balance_total' => number_format((float) (clone $base)->sum('balance'), 2, '.', ''),
            'overdue_count' => (clone $base)->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count(),
        ];
    }
}
