<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\ItemVariant;
use App\Modules\Catalog\Models\Party;
use App\Modules\Platform\Models\Warehouse;
use App\Modules\Sales\Application\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pedidos de clientes: alta con reserva, avance, entrega y cancelación.
 */
class OrderController extends Controller
{
    /**
     * Crea un pedido y reserva su stock.
     *
     * @param Request $request Datos del pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function store(Request $request, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'uuid', Rule::exists(Warehouse::class, 'id')->where('status', 'active')],
            'party_id' => ['required', 'uuid', Rule::exists(Party::class, 'id')->where('status', 'active')],
            'source' => ['sometimes', Rule::in(['whatsapp', 'counter', 'portal', 'phone', 'import'])],
            'delivery_type' => ['sometimes', Rule::in(['pickup', 'shipment', 'delivery'])],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prepaid.method' => ['required_with:prepaid', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'prepaid.amount' => ['required_with:prepaid', 'numeric', 'gt:0'],
            'prepaid.reference' => ['nullable', 'string', 'max:80'],
        ]);

        $prepaid = isset($data['prepaid']) ? [
            'method' => $data['prepaid']['method'],
            'amount' => number_format((float) $data['prepaid']['amount'], 2, '.', ''),
            'reference' => $data['prepaid']['reference'] ?? null,
        ] : null;
        /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string|null}> $lines */
        $lines = $this->normalizeLines($data['lines']);
        /** @var array{method: string, amount: numeric-string, reference: string|null}|null $prepaid */
        $order = $orders->create(
            (string) $request->user()?->tenant_id,
            $data['warehouse_id'],
            $data['party_id'],
            $lines,
            [
                'delivery_type' => $data['delivery_type'] ?? 'pickup',
                'source' => $data['source'] ?? 'counter',
                'requested_date' => $data['requested_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            $prepaid,
        );

        return response()->json(['data' => $order], 201);
    }

    /**
     * Edita las líneas y datos de un pedido sin entregas.
     *
     * @param Request $request Datos: líneas, tipo de entrega, fecha y notas.
     * @param string $id Pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function update(Request $request, string $id, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'delivery_type' => ['sometimes', Rule::in(['pickup', 'shipment', 'delivery'])],
            'requested_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.variant_id' => ['required', 'uuid', Rule::exists(ItemVariant::class, 'id')->where('status', 'active')],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
        /** @var list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct: numeric-string|null}> $lines */
        $lines = $this->normalizeLines($data['lines']);

        return response()->json(['data' => $orders->update($id, $lines, [
            'delivery_type' => $data['delivery_type'] ?? null,
            'requested_date' => $data['requested_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])]);
    }

    /**
     * Convierte las líneas validadas a texto decimal para el servicio.
     *
     * @param list<array<string, mixed>> $lines Líneas validadas.
     * @return list<array{variant_id: string, qty: string, unit_price: string, discount_pct: string|null}>
     */
    private function normalizeLines(array $lines): array
    {
        return array_map(fn (array $line): array => [
            'variant_id' => $line['variant_id'],
            'qty' => (string) $line['qty'],
            'unit_price' => (string) $line['unit_price'],
            'discount_pct' => isset($line['discount_pct']) ? (string) $line['discount_pct'] : null,
        ], $lines);
    }

    /**
     * Lista pedidos recientes con el nombre del cliente.
     *
     * @param Request $request Admite `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('sales.orders as o')
            ->join('cat.parties as p', 'p.id', '=', 'o.party_id')
            ->orderByDesc('o.created_at')->limit(200)
            ->select(['o.id', 'o.number', 'o.status', 'o.total', 'o.prepaid_total', 'o.delivery_type',
                'o.requested_date', 'o.created_at', 'p.name as party_name']);

        if ($request->filled('status')) {
            $query->where('o.status', $request->query('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Muestra un pedido con su detalle.
     *
     * @param string $id Pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function show(string $id, OrderService $orders): JsonResponse
    {
        return response()->json(['data' => $orders->find($id)]);
    }

    /**
     * Avanza el estado de despacho del pedido.
     *
     * @param string $id Pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function advance(string $id, OrderService $orders): JsonResponse
    {
        return response()->json(['data' => $orders->advance($id)]);
    }

    /**
     * Entrega (total o parcial) generando la venta.
     *
     * @param Request $request Datos: `deliveries`, `payments`, `on_credit`, `due_date`.
     * @param string $id Pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function deliver(Request $request, string $id, OrderService $orders): JsonResponse
    {
        $data = $request->validate([
            'deliveries' => ['required', 'array', 'min:1'],
            'deliveries.*.line_id' => ['required', 'uuid'],
            'deliveries.*.qty' => ['required', 'numeric', 'gt:0'],
            'on_credit' => ['sometimes', 'boolean'],
            'due_date' => ['required_if:on_credit,true', 'nullable', 'date'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:80'],
        ]);

        $deliveries = array_map(fn (array $d): array => ['line_id' => $d['line_id'], 'qty' => (string) $d['qty']], $data['deliveries']);
        $payments = array_map(fn (array $p): array => [
            'method' => $p['method'],
            'amount' => number_format((float) $p['amount'], 2, '.', ''),
            'reference' => $p['reference'] ?? null,
        ], $data['payments']);

        /** @var list<array{line_id: string, qty: numeric-string}> $deliveries */
        /** @var list<array{method: string, amount: numeric-string, reference: string|null}> $payments */
        $result = $orders->deliver($id, $deliveries, $payments, ! empty($data['on_credit']) ? ($data['due_date'] ?? null) : null);

        return response()->json(['data' => $result]);
    }

    /**
     * Cancela el pedido liberando sus reservas.
     *
     * @param Request $request Requiere `reason`.
     * @param string $id Pedido.
     * @param OrderService $orders Servicio de pedidos.
     */
    public function cancel(Request $request, string $id, OrderService $orders): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];

        return response()->json(['data' => $orders->cancel($id, $reason)]);
    }
}
