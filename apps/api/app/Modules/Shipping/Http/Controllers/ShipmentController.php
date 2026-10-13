<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\SaleException;
use App\Modules\Shipping\Models\Carrier;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Envíos: alta desde un pedido, cambios de estado con línea de tiempo.
 */
class ShipmentController extends Controller
{
    /**
     * Estados válidos de un envío.
     *
     * @var list<string>
     */
    private const STATUSES = ['pending', 'dispatched', 'in_transit', 'at_destination_office', 'delivered', 'returned', 'incident'];

    /**
     * Estados que cierran el envío.
     *
     * @var list<string>
     */
    private const FINAL = ['delivered', 'returned'];

    /**
     * Registra un envío (opcionalmente vinculado a un pedido).
     *
     * @param Request $request Datos del envío.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if (! DB::table('sales.orders')->where('id', $value)->exists()) {
                    $fail('El pedido indicado no existe.');
                }
            }],
            'carrier_id' => ['required', 'uuid', Rule::exists(Carrier::class, 'id')->where('status', 'active')],
            'delivery_mode' => ['sometimes', Rule::in(['agency_pickup', 'home_delivery'])],
            'destination_office' => ['nullable', 'string', 'max:160'],
            'recipient_name' => ['required', 'string', 'max:160'],
            'recipient_doc' => ['nullable', 'string', 'max:20'],
            'recipient_phone' => ['nullable', 'string', 'max:30'],
            'carrier_order_number' => ['nullable', 'string', 'max:40'],
            'pickup_code' => ['nullable', 'string', 'max:40'],
            'freight_amount' => ['nullable', 'numeric', 'min:0'],
            'freight_payer' => ['sometimes', Rule::in(['sender', 'recipient'])],
            'freight_status' => ['sometimes', Rule::in(['prepaid', 'pending', 'collect_on_delivery'])],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'packages_count' => ['nullable', 'integer', 'min:1', 'max:999'],
            'content_description' => ['nullable', 'string', 'max:255'],
        ]);

        $id = DB::transaction(fn (): string => $this->insert($request, $data));

        return response()->json(['data' => $this->detail($id)], 201);
    }

    /**
     * Lista envíos recientes con agencia y estado.
     *
     * @param Request $request Admite `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('ship.shipments as s')
            ->join('ship.carriers as c', 'c.id', '=', 's.carrier_id')
            ->orderByDesc('s.created_at')->limit(200)
            ->select(['s.id', 's.number', 's.status', 's.recipient_name', 's.destination_office', 's.pickup_code',
                's.carrier_order_number', 's.freight_amount', 's.freight_status', 's.cod_amount', 's.packages_count',
                's.created_at', 'c.name as carrier_name']);

        if ($request->filled('status')) {
            $query->where('s.status', $request->query('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Muestra un envío con su línea de tiempo.
     *
     * @param string $id Envío.
     */
    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->detail($id)]);
    }

    /**
     * Cambia el estado del envío y registra el evento.
     *
     * @param Request $request Datos: `status` y `note`.
     * @param string $id Envío.
     */
    public function changeStatus(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $id, $data): void {
            $shipment = DB::table('ship.shipments')->where('id', $id)->lockForUpdate()->first();
            if ($shipment === null) {
                throw new SaleException('not_found', 'Envío no encontrado.', 404);
            }
            if (in_array($shipment->status, self::FINAL, true)) {
                throw new SaleException('shipment_closed', 'El envío ya está cerrado.', 409);
            }

            $changes = ['status' => $data['status'], 'updated_at' => now(), 'version' => $shipment->version + 1];
            if ($data['status'] === 'dispatched' && $shipment->shipped_at === null) {
                $changes['shipped_at'] = now();
            }
            if ($data['status'] === 'delivered') {
                $changes['delivered_at'] = now();
            }
            DB::table('ship.shipments')->where('id', $id)->update($changes);
            $this->addEvent($request, $shipment->tenant_id, $id, $data['status'], $data['note'] ?? null);
        });

        return response()->json(['data' => $this->detail($id)]);
    }

    /**
     * Inserta el envío con su primer evento.
     *
     * @param Request $request Petición autenticada.
     * @param array<string, mixed> $data Datos validados.
     * @return string Identificador del envío.
     */
    private function insert(Request $request, array $data): string
    {
        $tenantId = (string) $request->user()?->tenant_id;
        $order = isset($data['order_id'])
            ? DB::table('sales.orders')->where('id', $data['order_id'])->first()
            : null;
        $branch = $order !== null
            ? $order
            : DB::table('core.branches')->where('status', 'active')->orderByDesc('is_main')->first();
        if ($branch === null) {
            throw new SaleException('branch_required', 'No hay una sucursal para registrar el envío.', 422);
        }
        $companyId = $order->company_id ?? $branch->company_id;
        $branchId = $order->branch_id ?? $branch->id;

        $id = (string) Str::uuid();
        DB::table('ship.shipments')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'company_id' => $companyId, 'branch_id' => $branchId,
            'number' => (int) DB::table('ship.shipments')->where('company_id', $companyId)->max('number') + 1,
            'order_id' => $order?->id, 'party_id' => $order?->party_id, 'carrier_id' => $data['carrier_id'],
            'delivery_mode' => $data['delivery_mode'] ?? 'agency_pickup',
            'destination_office' => $data['destination_office'] ?? null,
            'recipient_name' => $data['recipient_name'], 'recipient_doc' => $data['recipient_doc'] ?? null,
            'recipient_phone' => $data['recipient_phone'] ?? null,
            'carrier_order_number' => $data['carrier_order_number'] ?? null, 'pickup_code' => $data['pickup_code'] ?? null,
            'freight_amount' => $data['freight_amount'] ?? 0, 'freight_payer' => $data['freight_payer'] ?? 'sender',
            'freight_status' => $data['freight_status'] ?? 'prepaid', 'cod_amount' => $data['cod_amount'] ?? 0,
            'packages_count' => $data['packages_count'] ?? 1, 'content_description' => $data['content_description'] ?? null,
            'public_token' => Str::random(32),
            'created_by' => $request->user()?->getAuthIdentifier(), 'updated_by' => $request->user()?->getAuthIdentifier(),
        ]);
        $this->addEvent($request, $tenantId, $id, 'pending', 'Envío registrado');

        return $id;
    }

    /**
     * Agrega un evento a la línea de tiempo.
     *
     * @param Request $request Petición autenticada.
     * @param string $tenantId Cuenta.
     * @param string $shipmentId Envío.
     * @param string $status Estado.
     * @param string|null $note Nota.
     */
    private function addEvent(Request $request, string $tenantId, string $shipmentId, string $status, ?string $note): void
    {
        DB::table('ship.shipment_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'shipment_id' => $shipmentId,
            'status' => $status, 'note' => $note, 'created_by' => $request->user()?->getAuthIdentifier(),
        ]);
    }

    /**
     * Devuelve el envío con agencia y eventos.
     *
     * @param string $id Envío.
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        $shipment = DB::table('ship.shipments as s')
            ->join('ship.carriers as c', 'c.id', '=', 's.carrier_id')
            ->where('s.id', $id)->first(['s.*', 'c.name as carrier_name', 'c.tracking_url_template']);
        if ($shipment === null) {
            throw new SaleException('not_found', 'Envío no encontrado.', 404);
        }

        return (array) $shipment + [
            'events' => DB::table('ship.shipment_events')->where('shipment_id', $id)->orderBy('occurred_at')->get()->all(),
        ];
    }
}
