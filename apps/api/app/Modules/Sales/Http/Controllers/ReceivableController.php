<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cash\Application\CashService;
use App\Modules\Sales\Application\SaleException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Cuentas por cobrar: deudas de ventas a crédito y registro de cobros.
 */
class ReceivableController extends Controller
{
    /**
     * Lista los documentos con saldo pendiente, vencidos primero.
     *
     * @param Request $request Admite `party_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('sales.sales_documents as d')
            ->leftJoin('cat.parties as p', 'p.id', '=', 'd.party_id')
            ->where('d.status', 'confirmed')
            ->whereColumn('d.total', '>', 'd.paid_total')
            ->orderByRaw('d.due_date asc nulls last')
            ->select([
                'd.id', 'd.series', 'd.number', 'd.party_id', 'p.name as party_name', 'd.issued_at',
                'd.due_date', 'd.total', 'd.paid_total', 'd.balance',
            ]);

        if ($request->filled('party_id')) {
            $query->where('d.party_id', $request->query('party_id'));
        }

        $today = now()->toDateString();
        $rows = $query->limit(500)->get()->map(function (object $row) use ($today): array {
            $data = (array) $row;
            $data['overdue'] = $row->due_date !== null && $row->due_date < $today;

            return $data;
        });

        return response()->json([
            'data' => $rows,
            'summary' => [
                'balance_total' => number_format((float) $rows->sum(fn (array $r): float => (float) $r['balance']), 2, '.', ''),
                'overdue_count' => $rows->where('overdue', true)->count(),
            ],
        ]);
    }

    /**
     * Registra un cobro (total o parcial) contra un documento con saldo.
     *
     * @param Request $request Datos: `method`, `amount` y `reference`.
     * @param string $id Identificador del documento.
     * @param CashService $cash Servicio de caja.
     */
    public function collect(Request $request, string $id, CashService $cash): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);
        $amount = number_format((float) $data['amount'], 2, '.', '');
        assert(is_numeric($amount));

        $result = DB::transaction(function () use ($id, $data, $amount, $request, $cash): array {
            $doc = DB::table('sales.sales_documents')->where('id', $id)->lockForUpdate()->first();
            if ($doc === null) {
                throw new SaleException('not_found', 'Documento no encontrado.', 404);
            }
            if ($doc->status !== 'confirmed') {
                throw new SaleException('document_voided', 'El documento está anulado.', 409);
            }

            /** @var numeric-string $total */
            $total = (string) $doc->total;
            /** @var numeric-string $paid */
            $paid = (string) $doc->paid_total;
            $amountStr = bcadd('0', $amount, 2);
            $cash->assertSessionIfRequired(
                (string) $doc->branch_id,
                $request->user()?->getAuthIdentifier(),
                $data['method'] === 'cash',
            );
            $balance = bcsub($total, $paid, 2);
            if (bccomp($amountStr, $balance, 2) > 0) {
                throw new SaleException('amount_exceeds_balance', "El cobro supera el saldo pendiente ({$balance}).", 422);
            }

            $newPaid = bcadd($paid, $amountStr, 2);
            DB::table('sales.document_payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $doc->tenant_id, 'document_id' => $id,
                'method' => $data['method'], 'amount' => $amountStr, 'reference' => $data['reference'] ?? null,
                'cash_session_id' => $cash->currentSessionId((string) $request->user()?->getAuthIdentifier()),
                'created_by' => $request->user()?->getAuthIdentifier(),
            ]);
            DB::table('sales.sales_documents')->where('id', $id)->update([
                'paid_total' => $newPaid, 'updated_at' => now(), 'version' => $doc->version + 1,
            ]);

            return ['paid_total' => $newPaid, 'balance' => bcsub($balance, $amountStr, 2)];
        });

        return response()->json(['data' => $result], 201);
    }
}
