<?php

declare(strict_types=1);

namespace App\Modules\Cash\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Support\Decimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Listados de cobros (de clientes) y pagos (a proveedores) por rango de fechas.
 */
class PaymentLedgerController extends Controller
{
    /**
     * Lista los cobros recibidos.
     *
     * @param Request $request Filtros: `from`, `to` y `method`.
     */
    public function collections(Request $request): JsonResponse
    {
        $query = DB::table('sales.document_payments as p')
            ->join('sales.sales_documents as d', 'd.id', '=', 'p.document_id')
            ->leftJoin('cat.parties as c', 'c.id', '=', 'd.party_id')
            ->orderByDesc('p.paid_at')->limit(300)
            ->select(['p.id', 'p.method', 'p.amount', 'p.reference', 'p.paid_at', 'd.number as document_number',
                'c.name as party_name']);

        return $this->respond($request, $query);
    }

    /**
     * Lista los pagos hechos a proveedores.
     *
     * @param Request $request Filtros: `from`, `to` y `method`.
     */
    public function payments(Request $request): JsonResponse
    {
        $query = DB::table('purch.purchase_payments as p')
            ->join('purch.purchase_documents as d', 'd.id', '=', 'p.document_id')
            ->join('cat.parties as c', 'c.id', '=', 'd.supplier_id')
            ->orderByDesc('p.paid_at')->limit(300)
            ->select(['p.id', 'p.method', 'p.amount', 'p.reference', 'p.paid_at', 'd.number as document_number',
                'c.name as party_name']);

        return $this->respond($request, $query);
    }

    /**
     * Aplica los filtros comunes y arma la respuesta con el total.
     *
     * @param Request $request Filtros.
     * @param Builder $query Consulta base.
     */
    private function respond(Request $request, Builder $query): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'method' => ['nullable', 'string', 'max:12'],
        ]);
        if ($request->filled('from')) {
            $query->whereRaw('p.paid_at::date >= ?', [$request->query('from')]);
        }
        if ($request->filled('to')) {
            $query->whereRaw('p.paid_at::date <= ?', [$request->query('to')]);
        }
        if ($request->filled('method')) {
            $query->where('p.method', $request->query('method'));
        }
        $rows = $query->get();

        return response()->json([
            'data' => $rows,
            'meta' => ['total' => bcadd(Decimal::of($rows->sum('amount')), '0', 2)],
        ]);
    }
}
