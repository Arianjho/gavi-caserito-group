<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de gestión: ventas por día, rentabilidad por producto y
 * resultado estimado del periodo.
 */
class ReportController extends Controller
{
    /**
     * Ventas por día con totales, cobros por medio y resultado estimado.
     *
     * @param Request $request Admite `from` y `to` (por defecto, el mes en curso).
     */
    public function sales(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $daily = DB::table('sales.sales_documents')
            ->where('status', 'confirmed')->whereBetween('issued_at', [$from, $to])
            ->selectRaw('issued_at::date as day, count(*) as documents, sum(total) as total')
            ->groupByRaw('issued_at::date')->orderBy('day')->get();

        $byMethod = DB::table('sales.document_payments as p')
            ->join('sales.sales_documents as d', 'd.id', '=', 'p.document_id')
            ->where('d.status', 'confirmed')->where('p.method', '!=', 'advance')
            ->whereBetween('d.issued_at', [$from, $to])
            ->selectRaw('p.method, sum(p.amount) as total')->groupBy('p.method')->orderByDesc('total')->get();

        $margin = $this->marginTotals($from, $to);
        $expenses = Decimal::of(DB::table('purch.expenses')->where('status', 'active')
            ->whereBetween('paid_at', [$from, $to])->sum('amount'));

        return response()->json(['data' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'total' => bcadd(Decimal::of($daily->sum('total')), '0', 2), 'documents' => (int) $daily->sum('documents'),
            'daily' => $daily, 'by_method' => $byMethod,
            'revenue_net' => $margin['revenue'], 'cost' => $margin['cost'],
            'gross_margin' => bcsub($margin['revenue'], $margin['cost'], 2),
            'returns' => bcadd(Decimal::of(DB::table('sales.sale_returns')
                ->whereBetween('returned_at', [$from, $to])->sum('total')), '0', 2),
            'expenses' => bcadd($expenses, '0', 2),
            'estimated_result' => bcsub(bcsub($margin['revenue'], $margin['cost'], 2), $expenses, 2),
        ]]);
    }

    /**
     * Ventas y margen por producto.
     *
     * @param Request $request Admite `from` y `to`.
     */
    public function products(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = DB::table('sales.sales_document_lines as l')
            ->join('sales.sales_documents as d', 'd.id', '=', 'l.document_id')
            ->join('cat.item_variants as v', 'v.id', '=', 'l.variant_id')
            ->join('cat.items as i', 'i.id', '=', 'v.item_id')
            ->where('d.status', 'confirmed')->whereBetween('d.issued_at', [$from, $to])
            ->selectRaw('i.id, i.name, sum(l.qty) as qty, sum(l.total) as total, sum(l.sale_value) as revenue, '
                .'sum(l.qty * coalesce(l.unit_cost, 0)) as cost')
            ->groupBy('i.id', 'i.name')->orderByDesc('revenue')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (object $row): array => [
            'id' => $row->id, 'name' => $row->name, 'qty' => $row->qty, 'total' => $row->total,
            'revenue' => $row->revenue, 'cost' => $row->cost,
            'margin' => bcsub(Decimal::of($row->revenue), Decimal::of($row->cost), 2),
        ])->values()]);
    }

    /**
     * Ingreso neto (sin IGV) y costo de lo vendido en el periodo.
     *
     * @param Carbon $from Inicio.
     * @param Carbon $to Fin.
     * @return array{revenue: numeric-string, cost: numeric-string}
     */
    private function marginTotals(Carbon $from, Carbon $to): array
    {
        $row = DB::table('sales.sales_document_lines as l')
            ->join('sales.sales_documents as d', 'd.id', '=', 'l.document_id')
            ->where('d.status', 'confirmed')->whereBetween('d.issued_at', [$from, $to])
            ->selectRaw('coalesce(sum(l.sale_value), 0) as revenue, coalesce(sum(l.qty * coalesce(l.unit_cost, 0)), 0) as cost')
            ->first();
        $returned = DB::table('sales.sale_returns')->whereBetween('returned_at', [$from, $to])
            ->selectRaw('coalesce(sum(net_value), 0) as revenue, coalesce(sum(cost), 0) as cost')->first();

        return [
            'revenue' => bcsub(Decimal::of($row->revenue ?? 0), Decimal::of($returned->revenue ?? 0), 2),
            'cost' => bcsub(Decimal::of($row->cost ?? 0), Decimal::of($returned->cost ?? 0), 2),
        ];
    }

    /**
     * Rango de fechas pedido (por defecto, el mes en curso).
     *
     * @param Request $request Petición con `from` y `to` opcionales.
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }
}
