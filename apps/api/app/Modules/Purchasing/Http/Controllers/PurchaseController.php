<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Party;
use App\Modules\Platform\Models\Warehouse;
use App\Modules\Purchasing\Application\PurchaseReturnService;
use App\Modules\Purchasing\Application\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Compras a proveedores y cuentas por pagar.
 */
class PurchaseController extends Controller
{
    /**
     * Registra una compra.
     *
     * @param Request $request Datos de la compra.
     * @param PurchaseService $purchases Servicio de compras.
     */
    public function store(Request $request, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'supplier_id' => ['required', 'uuid', Rule::exists(Party::class, 'id')->where('status', 'active')->where('is_supplier', true)],
            'supplier_document' => ['nullable', 'string', 'max:40'],
            'issued_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'on_credit' => ['sometimes', 'boolean'],
            'due_date' => ['required_if:on_credit,true', 'nullable', 'date'],
            'method' => ['nullable', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        /** @var list<array{variant_id: string, qty: numeric-string, unit_cost: numeric-string}> $lines */
        $lines = array_map(fn (array $line): array => [
            'variant_id' => $line['variant_id'],
            'qty' => (string) $line['qty'],
            'unit_cost' => (string) $line['unit_cost'],
        ], $data['lines']);

        $document = $purchases->create(
            (string) $request->user()?->tenant_id,
            $data['warehouse_id'],
            $data['supplier_id'],
            $lines,
            [
                'supplier_document' => $data['supplier_document'] ?? null,
                'issued_date' => $data['issued_date'],
                'notes' => $data['notes'] ?? null,
                'due_date' => ! empty($data['on_credit']) ? ($data['due_date'] ?? null) : null,
                'method' => $data['method'] ?? null,
            ],
        );

        return response()->json(['data' => $document], 201);
    }

    /**
     * Lista compras recientes con el nombre del proveedor.
     */
    public function index(): JsonResponse
    {
        $rows = DB::table('purch.purchase_documents as d')
            ->join('cat.parties as p', 'p.id', '=', 'd.supplier_id')
            ->orderByDesc('d.created_at')->limit(200)
            ->get(['d.id', 'd.number', 'd.supplier_document', 'd.issued_date', 'd.total', 'd.paid_total',
                'd.balance', 'd.status', 'd.payment_condition', 'p.name as supplier_name']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Muestra una compra con su detalle.
     *
     * @param string $id Documento.
     * @param PurchaseService $purchases Servicio de compras.
     */
    public function show(string $id, PurchaseService $purchases): JsonResponse
    {
        return response()->json(['data' => $purchases->find($id)]);
    }

    /**
     * Registra un pago al proveedor.
     *
     * @param Request $request Datos: `method`, `amount`, `reference`.
     * @param string $id Documento.
     * @param PurchaseService $purchases Servicio de compras.
     */
    public function pay(Request $request, string $id, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $amount = number_format((float) $data['amount'], 2, '.', '');
        assert(is_numeric($amount));

        return response()->json(['data' => $purchases->pay($id, $data['method'], $amount, $data['reference'] ?? null)]);
    }

    /**
     * Anula una compra sin pagos y revierte el stock.
     *
     * @param Request $request Requiere `reason`.
     * @param string $id Documento.
     * @param PurchaseService $purchases Servicio de compras.
     */
    public function void(Request $request, string $id, PurchaseService $purchases): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json(['data' => $purchases->void($id, $reason)]);
    }

    /**
     * Cuentas por pagar: compras confirmadas con saldo.
     */
    public function payables(): JsonResponse
    {
        $rows = DB::table('purch.purchase_documents as d')
            ->join('cat.parties as p', 'p.id', '=', 'd.supplier_id')
            ->where('d.status', 'confirmed')->where('d.balance', '>', 0)
            ->orderBy('d.due_date')->limit(500)
            ->get(['d.id', 'd.number', 'd.supplier_document', 'd.due_date', 'd.total', 'd.paid_total', 'd.balance',
                'p.name as supplier_name'])
            ->map(function (object $row): object {
                $row->overdue = $row->due_date !== null && $row->due_date < now()->toDateString();

                return $row;
            });

        return response()->json([
            'data' => $rows,
            'summary' => [
                'balance_total' => number_format((float) $rows->sum(fn (object $r): float => (float) $r->balance), 2, '.', ''),
                'overdue_count' => $rows->where('overdue', true)->count(),
            ],
        ]);
    }

    /**
     * Devuelve mercadería al proveedor (parcial o total).
     *
     * @param Request $request Datos: `lines` y `reason`.
     * @param string $id Compra.
     * @param PurchaseReturnService $returns Servicio de devoluciones de compra.
     */
    public function returnGoods(Request $request, string $id, PurchaseReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        /** @var list<array{line_id: string, qty: numeric-string}> $lines */
        $lines = array_map(fn (array $l): array => [
            'line_id' => $l['line_id'], 'qty' => (string) $l['qty'],
        ], $data['lines']);

        return response()->json(['data' => $returns->create($id, $lines, $data['reason'])], 201);
    }
}
