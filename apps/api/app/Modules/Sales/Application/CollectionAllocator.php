<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Cash\Application\CashService;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Reparte un solo cobro de un cliente entre sus documentos con saldo,
 * empezando por el que vence primero.
 */
final class CollectionAllocator
{
    /**
     * Crea el servicio.
     *
     * @param CashService $cash Servicio de caja.
     */
    public function __construct(private readonly CashService $cash) {}

    /**
     * Aplica el monto a los documentos del cliente, del más antiguo al más nuevo.
     *
     * @param string $partyId Cliente.
     * @param string $method Medio de pago.
     * @param numeric-string $amount Importe recibido.
     * @param string|null $reference Referencia u operación.
     * @param string|null $userId Usuario que cobra.
     * @return list<array{document_id: string, number: string, applied: numeric-string}>
     *
     * @throws SaleException Si el monto supera la deuda del cliente.
     */
    public function allocate(string $partyId, string $method, string $amount, ?string $reference, ?string $userId): array
    {
        return DB::transaction(function () use ($partyId, $method, $amount, $reference, $userId): array {
            $docs = DB::table('sales.sales_documents')
                ->where('party_id', $partyId)->where('status', 'confirmed')
                ->whereColumn('total', '>', 'paid_total')
                ->orderByRaw('due_date asc nulls last')->orderBy('issued_at')
                ->lockForUpdate()->get();

            $debt = '0';
            foreach ($docs as $doc) {
                $debt = bcadd($debt, bcsub(Decimal::of($doc->total), Decimal::of($doc->paid_total), 2), 2);
            }
            if (bccomp($amount, $debt, 2) > 0) {
                throw new SaleException('amount_exceeds_balance', "El cobro supera la deuda del cliente ({$debt}).", 422);
            }
            if ($docs->isNotEmpty()) {
                $this->cash->assertSessionIfRequired((string) $docs->first()->branch_id, $userId, $method === 'cash');
            }

            return $this->apply(array_values($docs->all()), $method, $amount, $reference, $userId);
        });
    }

    /**
     * Inserta los cobros por documento hasta agotar el monto.
     *
     * @param list<stdClass> $docs Documentos bloqueados, en orden de cobro.
     * @param string $method Medio de pago.
     * @param numeric-string $amount Importe recibido.
     * @param string|null $reference Referencia.
     * @param string|null $userId Usuario.
     * @return list<array{document_id: string, number: string, applied: numeric-string}>
     */
    private function apply(array $docs, string $method, string $amount, ?string $reference, ?string $userId): array
    {
        $left = $amount;
        $applied = [];
        $sessionId = $this->cash->currentSessionId((string) $userId);
        foreach ($docs as $doc) {
            if (bccomp($left, '0', 2) <= 0) {
                break;
            }
            $balance = bcsub(Decimal::of($doc->total), Decimal::of($doc->paid_total), 2);
            $part = bccomp($left, $balance, 2) < 0 ? $left : $balance;
            DB::table('sales.document_payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $doc->tenant_id, 'document_id' => $doc->id,
                'method' => $method, 'amount' => $part, 'reference' => $reference,
                'cash_session_id' => $sessionId, 'created_by' => $userId,
            ]);
            DB::table('sales.sales_documents')->where('id', $doc->id)->update([
                'paid_total' => bcadd(Decimal::of($doc->paid_total), $part, 2),
                'updated_at' => now(), 'version' => $doc->version + 1,
            ]);
            $left = bcsub($left, $part, 2);
            $applied[] = ['document_id' => (string) $doc->id, 'number' => $doc->series.'-'.$doc->number, 'applied' => $part];
        }

        return $applied;
    }
}
