<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Party;
use App\Modules\Platform\Models\Warehouse;
use App\Modules\Sales\Application\SaleData;
use App\Modules\Sales\Application\SaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Registro, consulta y anulación de ventas.
 */
class SaleController extends Controller
{
    /**
     * Registra una venta.
     *
     * @param Request $request Datos: `warehouse_id`, `lines`, `payments` y `notes`.
     * @param SaleService $sales Servicio de ventas.
     */
    public function store(Request $request, SaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'party_id' => ['nullable', 'uuid', Rule::exists(Party::class, 'id')->where('status', 'active')],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'on_credit' => ['sometimes', 'boolean'],
            'due_date' => ['required_if:on_credit,true', 'nullable', 'date', 'after_or_equal:today'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $lines = array_map(fn (array $line): array => [
            'variant_id' => $line['variant_id'],
            'qty' => (string) $line['qty'],
            'unit_price' => isset($line['unit_price']) ? (string) $line['unit_price'] : null,
            'discount_pct' => isset($line['discount_pct']) ? (string) $line['discount_pct'] : null,
        ], $data['lines']);
        $payments = array_map(fn (array $payment): array => [
            'method' => $payment['method'],
            'amount' => number_format((float) $payment['amount'], 2, '.', ''),
            'reference' => $payment['reference'] ?? null,
        ], $data['payments']);

        /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string|null, discount_pct: numeric-string|null}> $lines */
        /** @var list<array{method: string, amount: numeric-string, reference: string|null}> $payments */
        $document = $sales->create(new SaleData(
            tenantId: (string) $request->user()?->tenant_id,
            warehouseId: $data['warehouse_id'],
            lines: $lines,
            payments: $payments,
            notes: $data['notes'] ?? null,
            partyId: $data['party_id'] ?? null,
            creditDueDate: ! empty($data['on_credit']) ? ($data['due_date'] ?? null) : null,
        ));

        return response()->json(['data' => $document], 201);
    }

    /**
     * Lista las ventas más recientes.
     *
     * @param Request $request Admite `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('sales.sales_documents')->orderByDesc('issued_at')->limit(100);

        if (in_array($request->query('status'), ['confirmed', 'voided'], true)) {
            $query->where('status', $request->query('status'));
        }

        return response()->json(['data' => $query->get([
            'id', 'doc_type', 'series', 'number', 'status', 'total', 'paid_total', 'issued_at', 'warehouse_id',
        ])]);
    }

    /**
     * Muestra una venta con sus líneas y cobros.
     *
     * @param string $id Identificador del documento.
     * @param SaleService $sales Servicio de ventas.
     */
    public function show(string $id, SaleService $sales): JsonResponse
    {
        return response()->json(['data' => $sales->find($id)]);
    }

    /**
     * Anula una venta devolviendo su stock.
     *
     * @param Request $request Requiere `reason`.
     * @param string $id Identificador del documento.
     * @param SaleService $sales Servicio de ventas.
     */
    public function void(Request $request, string $id, SaleService $sales): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json(['data' => $sales->void($id, $reason)]);
    }
}
