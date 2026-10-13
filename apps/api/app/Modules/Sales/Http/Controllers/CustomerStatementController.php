<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Estado de cuenta de un cliente: ventas, cobros y saldo corrido.
 */
class CustomerStatementController extends Controller
{
    /**
     * Devuelve los movimientos del cliente en orden cronológico con saldo acumulado.
     *
     * @param string $id Cliente.
     */
    public function show(string $id): JsonResponse
    {
        $party = DB::table('cat.parties')->where('id', $id)->first(['id', 'name', 'doc_type', 'doc_number']);
        if ($party === null) {
            throw new SaleException('not_found', 'Cliente no encontrado.', 404);
        }

        $sales = DB::table('sales.sales_documents')->where('party_id', $id)->where('status', 'confirmed')
            ->get(['id', 'series', 'number', 'issued_at', 'due_date', 'total']);
        $payments = DB::table('sales.document_payments as p')
            ->join('sales.sales_documents as d', 'd.id', '=', 'p.document_id')
            ->where('d.party_id', $id)->where('d.status', 'confirmed')
            ->get(['p.id', 'p.method', 'p.amount', 'p.paid_at', 'd.series', 'd.number']);

        $events = [];
        foreach ($sales as $sale) {
            $events[] = [
                'at' => (string) $sale->issued_at, 'type' => 'sale', 'label' => "Venta {$sale->series}-{$sale->number}",
                'charge' => Decimal::of($sale->total), 'payment' => '0.00', 'due_date' => $sale->due_date,
            ];
        }
        foreach ($payments as $payment) {
            $events[] = [
                'at' => (string) $payment->paid_at, 'type' => 'payment',
                'label' => "Cobro {$payment->series}-{$payment->number} ({$payment->method})",
                'charge' => '0.00', 'payment' => Decimal::of($payment->amount), 'due_date' => null,
            ];
        }
        usort($events, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        $balance = '0';
        foreach ($events as &$event) {
            $balance = bcsub(bcadd($balance, $event['charge'], 2), $event['payment'], 2);
            $event['balance'] = $balance;
        }
        unset($event);

        return response()->json(['data' => [
            'party' => $party, 'events' => $events, 'balance' => bcadd($balance, '0', 2),
        ]]);
    }
}
