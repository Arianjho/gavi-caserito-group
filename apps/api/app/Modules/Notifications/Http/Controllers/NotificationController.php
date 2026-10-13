<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Canal de WhatsApp del negocio y avisos a clientes (comprobante de venta,
 * estado de pedido y recordatorio de deuda).
 */
class NotificationController extends Controller
{
    /**
     * Estados de pedido en lenguaje para el cliente.
     *
     * @var array<string, string>
     */
    private const ORDER_STATES = [
        'confirmed' => 'confirmado', 'in_dispatch' => 'en preparación', 'ready' => 'listo',
        'delivered' => 'entregado', 'cancelled' => 'cancelado',
    ];

    /**
     * Muestra la configuración del canal (sin la clave).
     *
     * @param Request $request Petición autenticada.
     */
    public function show(Request $request): JsonResponse
    {
        $account = DB::table('notif.channel_accounts')->where('tenant_id', $request->user()?->tenant_id)->first();

        return response()->json(['data' => $account === null ? null : [
            'base_url' => $account->base_url, 'instance' => $account->instance,
            'enabled' => (bool) $account->enabled, 'has_api_key' => true,
        ]]);
    }

    /**
     * Crea o actualiza la cuenta de WhatsApp del negocio.
     *
     * @param Request $request Datos de conexión.
     */
    public function update(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()?->tenant_id;
        $existing = DB::table('notif.channel_accounts')->where('tenant_id', $tenantId)->first();
        $data = $request->validate([
            'base_url' => ['required', 'url', 'max:190'],
            'instance' => ['required', 'string', 'max:80'],
            'api_key' => [$existing === null ? 'required' : 'nullable', 'string', 'max:200'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $values = [
            'base_url' => $data['base_url'], 'instance' => $data['instance'],
            'enabled' => $data['enabled'] ?? true, 'updated_at' => now(),
        ];
        if (! empty($data['api_key'])) {
            $values['api_key'] = Crypt::encryptString($data['api_key']);
        }
        if ($existing === null) {
            DB::table('notif.channel_accounts')->insert($values + [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'created_at' => now(),
            ]);
        } else {
            DB::table('notif.channel_accounts')->where('tenant_id', $tenantId)->update($values);
        }

        return $this->show($request);
    }

    /**
     * Lista los mensajes recientes.
     *
     * @param Request $request Petición autenticada.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('notif.messages as m')
            ->leftJoin('cat.parties as p', 'p.id', '=', 'm.party_id')
            ->where('m.tenant_id', $request->user()?->tenant_id)
            ->orderByDesc('m.queued_at')->limit(100)
            ->get(['m.id', 'm.event', 'm.phone', 'm.body', 'm.status', 'm.error', 'm.queued_at', 'm.sent_at', 'p.name as party_name']);

        return response()->json(['data' => $rows]);
    }

    /**
     * Envía un mensaje de prueba al teléfono indicado.
     *
     * @param Request $request Datos: `phone`.
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function test(Request $request, NotificationService $service): JsonResponse
    {
        $phone = $request->validate(['phone' => ['required', 'string', 'max:20']])['phone'];
        $tenantId = (string) $request->user()?->tenant_id;
        $account = DB::table('notif.channel_accounts')->where('tenant_id', $tenantId)->first();
        if ($account === null || ! $account->enabled) {
            throw new SaleException('channel_not_configured', 'Configura y activa WhatsApp primero.', 422);
        }

        return $this->dispatchMessage($tenantId, $service->queueTest($tenantId, $phone));
    }

    /**
     * Avisa al cliente su comprobante de venta.
     *
     * @param Request $request Petición autenticada.
     * @param string $id Venta.
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function notifySale(Request $request, string $id, NotificationService $service): JsonResponse
    {
        $sale = $this->saleOf($id);
        $body = sprintf(
            'Hola %s, gracias por tu compra en %s. Documento %s-%s por S/ %s.',
            $this->firstName($sale), $this->companyName($sale), $sale->series, $sale->number, $sale->total,
        );

        return $this->dispatchMessage(
            (string) $request->user()?->tenant_id,
            $service->queueForParty((string) $request->user()?->tenant_id, 'sale_receipt', (string) $sale->party_id, $body, 'sale', $id),
        );
    }

    /**
     * Recuerda al cliente el saldo pendiente de una venta a crédito.
     *
     * @param Request $request Petición autenticada.
     * @param string $id Venta con saldo.
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function notifyDebt(Request $request, string $id, NotificationService $service): JsonResponse
    {
        $sale = $this->saleOf($id);
        if (bccomp(Decimal::of($sale->balance), '0', 2) <= 0) {
            throw new SaleException('no_balance', 'Esta venta no tiene saldo pendiente.', 422);
        }
        $due = $sale->due_date !== null ? ', con vencimiento el '.Carbon::parse((string) $sale->due_date)->format('d/m/Y') : '';
        $body = sprintf(
            'Hola %s, te recordamos que tienes un saldo pendiente de S/ %s por el documento %s-%s%s. %s',
            $this->firstName($sale), $sale->balance, $sale->series, $sale->number, $due, $this->companyName($sale),
        );

        return $this->dispatchMessage(
            (string) $request->user()?->tenant_id,
            $service->queueForParty((string) $request->user()?->tenant_id, 'debt_reminder', (string) $sale->party_id, $body, 'receivable', $id),
        );
    }

    /**
     * Informa al cliente el estado actual de su pedido.
     *
     * @param Request $request Petición autenticada.
     * @param string $id Pedido.
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function notifyOrder(Request $request, string $id, NotificationService $service): JsonResponse
    {
        $order = DB::table('sales.orders as o')->join('cat.parties as p', 'p.id', '=', 'o.party_id')
            ->join('core.companies as c', 'c.id', '=', 'o.company_id')
            ->where('o.id', $id)
            ->first(['o.number', 'o.status', 'o.party_id', 'o.public_token', 'p.name as party_name', 'c.trade_name', 'c.legal_name']);
        if ($order === null) {
            throw new SaleException('not_found', 'Pedido no encontrado.', 404);
        }
        $body = sprintf(
            'Hola %s, tu pedido #%s en %s está %s. Síguelo aquí: %s/seguimiento/%s',
            Str::of((string) $order->party_name)->before(' '), $order->number,
            $order->trade_name ?? $order->legal_name, self::ORDER_STATES[$order->status] ?? $order->status,
            rtrim((string) config('app.frontend_url'), '/'), $order->public_token,
        );
        $tenantId = (string) $request->user()?->tenant_id;

        return $this->dispatchMessage(
            $tenantId,
            $service->queueForParty($tenantId, 'order_status', (string) $order->party_id, $body, 'order', $id),
        );
    }

    /**
     * Despacha el trabajo de envío y responde con el mensaje encolado.
     *
     * @param string $tenantId Cuenta.
     * @param string $messageId Mensaje encolado.
     */
    private function dispatchMessage(string $tenantId, string $messageId): JsonResponse
    {
        SendNotification::dispatch($tenantId, $messageId);

        return response()->json(['data' => DB::table('notif.messages')->where('id', $messageId)->first()], 201);
    }

    /**
     * Venta con cliente y datos para componer el mensaje.
     *
     * @param string $id Venta.
     */
    private function saleOf(string $id): stdClass
    {
        $sale = DB::table('sales.sales_documents as s')->join('core.companies as c', 'c.id', '=', 's.company_id')
            ->leftJoin('cat.parties as p', 'p.id', '=', 's.party_id')
            ->where('s.id', $id)
            ->first(['s.series', 's.number', 's.total', 's.balance', 's.party_id', 's.due_date',
                'c.trade_name', 'c.legal_name', 'p.name as party_name']);
        if ($sale === null) {
            throw new SaleException('not_found', 'Venta no encontrada.', 404);
        }
        if ($sale->party_id === null) {
            throw new SaleException('party_required', 'La venta no tiene un cliente al que avisar.', 422);
        }

        return $sale;
    }

    /**
     * Primer nombre del cliente.
     *
     * @param stdClass $sale Venta con `party_name`.
     */
    private function firstName(stdClass $sale): string
    {
        return (string) Str::of((string) $sale->party_name)->before(' ');
    }

    /**
     * Nombre comercial de la empresa.
     *
     * @param stdClass $sale Venta con datos de empresa.
     */
    private function companyName(stdClass $sale): string
    {
        return (string) ($sale->trade_name ?? $sale->legal_name);
    }
}
