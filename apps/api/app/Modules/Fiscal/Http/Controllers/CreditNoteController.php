<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\CreditNoteService;
use App\Modules\Fiscal\Application\FiscalRecordSync;
use App\Modules\Fiscal\Jobs\SendFiscalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Notas de crédito electrónicas: emisión sobre un comprobante, bandeja y reintento.
 */
class CreditNoteController extends Controller
{
    /**
     * Motivos del catálogo 09 SUNAT que admite la emisión.
     *
     * @var list<string>
     */
    private const REASONS = ['01', '02', '03', '04', '05', '06', '07', '08', '09'];

    /**
     * Emite una nota de crédito sobre un comprobante aceptado.
     *
     * @param Request $request Datos: `reason_code`, `reason_text`, `lines` o `amount`, `refund_method`.
     * @param string $id Comprobante (`fiscal.documents`).
     * @param CreditNoteService $notes Servicio de notas de crédito.
     */
    public function store(Request $request, string $id, CreditNoteService $notes): JsonResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', Rule::in(self::REASONS)],
            'reason_text' => ['required', 'string', 'max:250'],
            'lines' => ['sometimes', 'array'],
            'lines.*.line_id' => ['required', 'uuid'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'refund_method' => ['nullable', Rule::in(['cash', 'card', 'yape', 'plin', 'transfer', 'other'])],
        ]);
        /** @var list<array{line_id: string, qty: numeric-string}> $lines */
        $lines = array_map(
            fn (array $line): array => ['line_id' => (string) $line['line_id'], 'qty' => (string) $line['qty']],
            $data['lines'] ?? [],
        );
        /** @var numeric-string|null $amount */
        $amount = isset($data['amount']) ? (string) $data['amount'] : null;

        $queued = $notes->enqueue($id, $data['reason_code'], $data['reason_text'], $lines, $amount, $data['refund_method'] ?? null);
        SendFiscalRecord::dispatch($queued['tenant_id'], 'note', $queued['id']);

        return response()->json(['data' => $this->detail($queued['id'])], 202);
    }

    /**
     * Lista notas de crédito con su estado.
     *
     * @param Request $request Admite `status` y `document_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('fiscal.credit_notes as n')
            ->join('fiscal.documents as d', 'd.id', '=', 'n.document_id')
            ->orderByDesc('n.created_at')->limit(200)
            ->select(['n.id', 'n.document_id', 'n.reason_code', 'n.reason_text', 'n.total', 'n.series', 'n.number', 'n.status',
                'n.sunat_code', 'n.sunat_description', 'n.last_error', 'n.attempts', 'n.created_at',
                'd.cpe_type', 'd.series as doc_series', 'd.number as doc_number']);

        foreach (['status' => 'n.status', 'document_id' => 'n.document_id'] as $param => $column) {
            if ($request->filled($param)) {
                $query->where($column, $request->query($param));
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Reintenta el envío de una nota en error o pendiente.
     *
     * @param string $id Nota de crédito.
     * @param FiscalRecordSync $sync Envío de notas y guías.
     */
    public function retry(string $id, FiscalRecordSync $sync): JsonResponse
    {
        $note = DB::table('fiscal.credit_notes')->where('id', $id)->first();
        abort_if($note === null, 404);

        if ($note->efact_id !== null) {
            $sync->refresh('note', $id);
        } else {
            DB::table('fiscal.credit_notes')->where('id', $id)->update(['status' => 'queued', 'last_error' => null]);
            SendFiscalRecord::dispatch((string) $note->tenant_id, 'note', $id);
        }

        return response()->json(['data' => $this->detail($id)]);
    }

    /**
     * Detalle de una nota.
     *
     * @param string $id Nota de crédito.
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        return (array) DB::table('fiscal.credit_notes')->where('id', $id)->first();
    }
}
