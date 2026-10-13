<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\FiscalService;
use App\Modules\Fiscal\Jobs\SendFiscalDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Comprobantes electrónicos: emisión desde una venta, bandeja y reintentos.
 */
class FiscalDocumentController extends Controller
{
    /**
     * Emite un comprobante a partir de una venta.
     *
     * @param Request $request Datos: `cpe_type` (`01` factura o `03` boleta).
     * @param string $id Venta.
     * @param FiscalService $fiscal Servicio fiscal.
     */
    public function emit(Request $request, string $id, FiscalService $fiscal): JsonResponse
    {
        $type = $request->validate(['cpe_type' => ['required', Rule::in(['01', '03'])]])['cpe_type'];

        $queued = $fiscal->enqueue($id, $type);
        SendFiscalDocument::dispatch($queued['tenant_id'], $queued['id']);

        return response()->json(['data' => $this->detail($queued['id'])], 202);
    }

    /**
     * Lista comprobantes con su estado.
     *
     * @param Request $request Admite `status`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('fiscal.documents as f')
            ->join('sales.sales_documents as s', 's.id', '=', 'f.sales_document_id')
            ->orderByDesc('f.created_at')->limit(200)
            ->select(['f.id', 'f.cpe_type', 'f.series', 'f.number', 'f.status', 'f.sunat_code', 'f.sunat_description',
                'f.last_error', 'f.attempts', 'f.created_at', 's.total', 's.series as sale_series', 's.number as sale_number']);

        if ($request->filled('status')) {
            $query->where('f.status', $request->query('status'));
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Reintenta el envío de un comprobante en error o pendiente.
     *
     * @param string $id Comprobante.
     * @param FiscalService $fiscal Servicio fiscal.
     */
    public function retry(string $id, FiscalService $fiscal): JsonResponse
    {
        $doc = DB::table('fiscal.documents')->where('id', $id)->first();
        abort_if($doc === null, 404);

        if ($doc->efact_id !== null) {
            $fiscal->refresh($id);
        } else {
            DB::table('fiscal.documents')->where('id', $id)->update(['status' => 'queued', 'last_error' => null]);
            SendFiscalDocument::dispatch((string) $doc->tenant_id, $id);
        }

        return response()->json(['data' => $this->detail($id)]);
    }

    /**
     * Detalle de un comprobante.
     *
     * @param string $id Comprobante.
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        return (array) DB::table('fiscal.documents')->where('id', $id)->first();
    }
}
