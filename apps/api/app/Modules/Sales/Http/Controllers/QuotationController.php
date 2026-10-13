<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Party;
use App\Modules\Platform\Models\Company;
use App\Modules\Platform\Models\Warehouse;
use App\Modules\Sales\Application\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cotizaciones: alta, consulta, rechazo y conversión en pedido.
 */
class QuotationController extends Controller
{
    /**
     * Registra una cotización.
     *
     * @param Request $request Datos de la cotización.
     * @param QuotationService $quotations Servicio de cotizaciones.
     */
    public function store(Request $request, QuotationService $quotations): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'uuid', Rule::exists(Company::class, 'id')->where('status', 'active')],
            'party_id' => ['required', 'uuid', Rule::exists(Party::class, 'id')->where('status', 'active')],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string|null}> $lines */
        $lines = array_map(fn (array $line): array => [
            'variant_id' => $line['variant_id'], 'qty' => (string) $line['qty'],
            'unit_price' => (string) $line['unit_price'],
            'discount_pct' => isset($line['discount_pct']) ? (string) $line['discount_pct'] : null,
        ], $data['lines']);

        $quotation = $quotations->create(
            (string) $request->user()?->tenant_id, $data['company_id'], $data['party_id'],
            $lines, $data['valid_until'] ?? null, $data['notes'] ?? null,
        );

        return response()->json(['data' => $quotation], 201);
    }

    /**
     * Lista cotizaciones recientes con el nombre del cliente.
     *
     * @param Request $request Admite `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('sales.quotations as q')
            ->join('cat.parties as p', 'p.id', '=', 'q.party_id')
            ->orderByDesc('q.created_at')->limit(200)
            ->select(['q.id', 'q.number', 'q.status', 'q.total', 'q.valid_until', 'q.created_at', 'q.order_id', 'p.name as party_name']);

        if ($request->filled('status')) {
            $query->where('q.status', $request->query('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Muestra una cotización con su detalle.
     *
     * @param string $id Cotización.
     * @param QuotationService $quotations Servicio de cotizaciones.
     */
    public function show(string $id, QuotationService $quotations): JsonResponse
    {
        return response()->json(['data' => $quotations->find($id)]);
    }

    /**
     * Rechaza una cotización abierta.
     *
     * @param string $id Cotización.
     * @param QuotationService $quotations Servicio de cotizaciones.
     */
    public function reject(string $id, QuotationService $quotations): JsonResponse
    {
        return response()->json(['data' => $quotations->reject($id)]);
    }

    /**
     * Convierte la cotización en pedido.
     *
     * @param Request $request Datos: `warehouse_id` y datos de entrega.
     * @param string $id Cotización.
     * @param QuotationService $quotations Servicio de cotizaciones.
     */
    public function convert(Request $request, string $id, QuotationService $quotations): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'delivery_type' => ['sometimes', Rule::in(['pickup', 'shipment', 'delivery'])],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $quotations->convert($id, $data['warehouse_id'], [
            'delivery_type' => $data['delivery_type'] ?? 'pickup', 'source' => 'counter',
            'requested_date' => $data['requested_date'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return response()->json(['data' => $order], 201);
    }
}
