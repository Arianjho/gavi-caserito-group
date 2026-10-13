<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Seguimiento público por enlace: el cliente ve el estado de su pedido o
 * envío sin iniciar sesión. Solo expone datos mínimos, nunca identificadores
 * internos ni montos de caja.
 */
class PublicTrackingController extends Controller
{
    /**
     * Estados del pedido en lenguaje para el cliente.
     *
     * @var array<string, string>
     */
    private const ORDER_STATES = [
        'confirmed' => 'Pedido confirmado', 'in_dispatch' => 'En preparación', 'ready' => 'Listo',
        'delivered' => 'Entregado', 'cancelled' => 'Cancelado',
    ];

    /**
     * Estados del envío en lenguaje para el cliente.
     *
     * @var array<string, string>
     */
    private const SHIPMENT_STATES = [
        'pending' => 'Por despachar', 'dispatched' => 'Despachado', 'in_transit' => 'En tránsito',
        'at_destination_office' => 'En la agencia de destino', 'delivered' => 'Entregado',
        'returned' => 'Devuelto', 'incident' => 'Con incidencia',
    ];

    /**
     * Muestra el seguimiento del pedido o envío al que pertenece el token.
     *
     * @param string $token Enlace público del pedido o del envío.
     */
    public function show(string $token): JsonResponse
    {
        $data = TenantContext::asProvider(fn (): ?array => $this->findByToken($token));

        if ($data === null) {
            return response()->json([
                'code' => 'not_found', 'message' => 'No encontramos ese seguimiento. Revisa el enlace.',
            ], 404);
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Busca el token entre pedidos y envíos.
     *
     * @param string $token Token público.
     * @return array<string, mixed>|null
     */
    private function findByToken(string $token): ?array
    {
        $order = DB::table('sales.orders')->where('public_token', $token)->first();
        if ($order !== null) {
            return $this->orderView($order);
        }
        $shipment = DB::table('ship.shipments')->where('public_token', $token)->first();

        return $shipment === null ? null : $this->shipmentView($shipment);
    }

    /**
     * Vista pública de un pedido con sus envíos.
     *
     * @param stdClass $order Fila del pedido.
     * @return array<string, mixed>
     */
    private function orderView(stdClass $order): array
    {
        $lines = DB::table('sales.order_lines')->where('order_id', $order->id)->orderBy('line_no')
            ->get(['description', 'qty_ordered', 'qty_delivered']);
        $shipments = DB::table('ship.shipments')->where('order_id', $order->id)->orderBy('created_at')->get();

        return [
            'kind' => 'order',
            'business' => $this->companyName((string) $order->company_id),
            'brand' => $this->brandOf((string) $order->company_id),
            'number' => $order->number,
            'status' => $order->status,
            'status_label' => self::ORDER_STATES[$order->status] ?? $order->status,
            'requested_date' => $order->requested_date,
            'total' => $order->total,
            'prepaid' => $order->prepaid_total,
            'lines' => $lines->map(fn (stdClass $l): array => [
                'description' => $l->description, 'qty' => $l->qty_ordered, 'delivered' => $l->qty_delivered,
            ])->all(),
            'shipments' => $shipments->map(fn (stdClass $s): array => $this->shipmentView($s))->all(),
        ];
    }

    /**
     * Vista pública de un envío con su línea de tiempo.
     *
     * @param stdClass $shipment Fila del envío.
     * @return array<string, mixed>
     */
    private function shipmentView(stdClass $shipment): array
    {
        $carrier = DB::table('ship.carriers')->where('id', $shipment->carrier_id)->first(['name', 'tracking_url_template']);
        $events = DB::table('ship.shipment_events')->where('shipment_id', $shipment->id)
            ->orderBy('occurred_at')->get(['status', 'note', 'occurred_at']);
        $code = $shipment->pickup_code;
        $url = $carrier?->tracking_url_template !== null && $code !== null
            ? str_replace('{code}', rawurlencode((string) $code), (string) $carrier->tracking_url_template)
            : null;

        return [
            'kind' => 'shipment',
            'business' => $this->companyName((string) $shipment->company_id),
            'brand' => $this->brandOf((string) $shipment->company_id),
            'number' => $shipment->number,
            'status' => $shipment->status,
            'status_label' => self::SHIPMENT_STATES[$shipment->status] ?? $shipment->status,
            'carrier' => $carrier?->name,
            'destination' => $shipment->destination_office,
            'pickup_code' => $code,
            'tracking_url' => $url,
            'events' => $events->map(fn (stdClass $e): array => [
                'label' => self::SHIPMENT_STATES[$e->status] ?? $e->status, 'note' => $e->note, 'at' => $e->occurred_at,
            ])->all(),
        ];
    }

    /**
     * Nombre comercial de la empresa.
     *
     * @param string $companyId Empresa.
     */
    private function companyName(string $companyId): string
    {
        $company = DB::table('core.companies')->where('id', $companyId)->first(['trade_name', 'legal_name']);

        return (string) ($company->trade_name ?? $company->legal_name ?? '');
    }

    /**
     * Color y lema de la marca de la cuenta dueña de la empresa.
     *
     * @param string $companyId Empresa.
     * @return array{color: string|null, tagline: string|null}
     */
    private function brandOf(string $companyId): array
    {
        $row = DB::table('core.companies as c')->join('core.tenants as t', 't.id', '=', 'c.tenant_id')
            ->where('c.id', $companyId)->first(['t.brand_color', 't.brand_tagline']);

        return ['color' => $row->brand_color ?? null, 'tagline' => $row->brand_tagline ?? null];
    }
}
