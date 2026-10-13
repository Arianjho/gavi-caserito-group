<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Recordatorio automático de deudas: avisa por WhatsApp a los clientes con
 * consentimiento cuyo saldo vence pronto o ya venció, sin repetir el aviso
 * del mismo documento antes de `$repeatDays`.
 */
final class DebtReminderRunner
{
    /**
     * Crea el servicio.
     *
     * @param NotificationService $service Servicio de notificaciones.
     */
    public function __construct(private readonly NotificationService $service) {}

    /**
     * Encola los recordatorios de todas las cuentas con WhatsApp activo.
     *
     * @param int $daysBefore Días de anticipación al vencimiento.
     * @param int $repeatDays Días mínimos entre avisos del mismo documento.
     * @return int Cantidad de mensajes encolados.
     */
    public function run(int $daysBefore = 2, int $repeatDays = 7): int
    {
        $tenants = TenantContext::asProvider(fn () => DB::table('notif.channel_accounts')
            ->where('enabled', true)->pluck('tenant_id')->all());

        $queued = 0;
        foreach ($tenants as $tenantId) {
            $previous = TenantContext::current();
            TenantContext::useTenant((string) $tenantId);
            try {
                $queued += $this->runTenant((string) $tenantId, $daysBefore, $repeatDays);
            } finally {
                $previous === '' ? TenantContext::clear() : TenantContext::useTenant($previous);
            }
        }

        return $queued;
    }

    /**
     * Procesa una cuenta.
     *
     * @param string $tenantId Cuenta.
     * @param int $daysBefore Días de anticipación.
     * @param int $repeatDays Días entre avisos.
     */
    private function runTenant(string $tenantId, int $daysBefore, int $repeatDays): int
    {
        $limit = Carbon::now()->addDays($daysBefore)->toDateString();
        $sales = DB::table('sales.sales_documents as s')
            ->join('core.companies as c', 'c.id', '=', 's.company_id')
            ->join('cat.parties as p', 'p.id', '=', 's.party_id')
            ->where('s.status', 'confirmed')->where('p.whatsapp_opt_in', true)
            ->whereRaw('s.balance > 0')->whereNotNull('s.due_date')->where('s.due_date', '<=', $limit)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('notif.messages as m')
                ->whereColumn('m.ref_id', 's.id')->where('m.event', 'debt_reminder')
                ->where('m.queued_at', '>', Carbon::now()->subDays($repeatDays)))
            ->orderBy('s.due_date')->limit(200)
            ->get(['s.id', 's.series', 's.number', 's.balance', 's.due_date', 's.party_id',
                'p.name as party_name', 'c.trade_name', 'c.legal_name']);

        $queued = 0;
        foreach ($sales as $sale) {
            try {
                $messageId = $this->service->queueForParty(
                    $tenantId, 'debt_reminder', (string) $sale->party_id, $this->body($sale), 'receivable', (string) $sale->id,
                );
            } catch (SaleException) {
                continue;
            }
            SendNotification::dispatch($tenantId, $messageId);
            $queued++;
        }

        return $queued;
    }

    /**
     * Compone el texto del recordatorio.
     *
     * @param stdClass $sale Venta con cliente y empresa.
     */
    private function body(stdClass $sale): string
    {
        $due = Carbon::parse((string) $sale->due_date);
        $when = $due->isPast() && ! $due->isToday()
            ? 'venció el '.$due->format('d/m/Y')
            : 'vence el '.$due->format('d/m/Y');
        $first = explode(' ', trim((string) $sale->party_name))[0];

        return sprintf(
            'Hola %s, te recordamos que tu saldo de S/ %s del documento %s-%s %s. %s',
            $first, $sale->balance, $sale->series, $sale->number, $when, $sale->trade_name ?? $sale->legal_name,
        );
    }
}
