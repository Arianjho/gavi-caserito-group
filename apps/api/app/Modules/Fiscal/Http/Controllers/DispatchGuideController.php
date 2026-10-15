<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Application\DispatchGuideService;
use App\Modules\Fiscal\Application\FiscalRecordSync;
use App\Modules\Fiscal\Jobs\SendFiscalRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Guías de remisión remitente: emisión, bandeja y reintento.
 */
class DispatchGuideController extends Controller
{
    /**
     * Emite una guía de remisión.
     *
     * @param Request $request Datos del traslado (ver reglas).
     * @param DispatchGuideService $guides Servicio de guías.
     */
    public function store(Request $request, DispatchGuideService $guides): JsonResponse
    {
        $data = $request->validate([
            'sales_document_id' => ['nullable', 'uuid'],
            'shipment_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'transfer_reason' => ['required', Rule::in(['01', '02', '04', '08', '09', '13', '14', '18', '19'])],
            'transport_mode' => ['required', Rule::in(['01', '02'])],
            'transfer_date' => ['required', 'date'],
            'weight' => ['required', 'numeric', 'gt:0'],
            'weight_unit' => ['sometimes', Rule::in(['KGM', 'TNE'])],
            'packages' => ['nullable', 'integer', 'min:1'],
            'arrival_ubigeo' => ['required', 'string', 'size:6'],
            'arrival_address' => ['required', 'string', 'max:255'],
            'recipient' => ['sometimes', 'array'],
            'recipient.doc_type' => ['required_with:recipient', Rule::in(['DNI', 'RUC', 'CE', 'PAS'])],
            'recipient.doc_number' => ['required_with:recipient', 'string', 'max:15'],
            'recipient.name' => ['required_with:recipient', 'string', 'max:255'],
            'carrier' => ['sometimes', 'array'],
            'carrier.doc_number' => ['required_with:carrier', 'string', 'size:11'],
            'carrier.name' => ['nullable', 'string', 'max:255'],
            'carrier.mtc' => ['nullable', 'string', 'max:30'],
            'vehicle_plate' => ['nullable', 'string', 'max:10'],
            'driver' => ['sometimes', 'array'],
            'driver.doc_type' => ['nullable', Rule::in(['DNI', 'CE', 'PAS'])],
            'driver.doc_number' => ['required_with:driver', 'string', 'max:15'],
            'driver.first_name' => ['required_with:driver', 'string', 'max:100'],
            'driver.last_name' => ['required_with:driver', 'string', 'max:100'],
            'driver.license' => ['required_with:driver', 'string', 'max:20'],
            'items' => ['sometimes', 'array'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:5'],
        ]);

        $queued = $guides->enqueue($data);
        SendFiscalRecord::dispatch($queued['tenant_id'], 'guide', $queued['id']);

        return response()->json(['data' => $this->detail($queued['id'])], 202);
    }

    /**
     * Lista guías con su estado.
     *
     * @param Request $request Admite `status` y `sales_document_id`.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('fiscal.dispatch_guides')->orderByDesc('created_at')->limit(200)
            ->select(['id', 'sales_document_id', 'transfer_reason', 'transport_mode', 'transfer_date', 'weight', 'weight_unit',
                'recipient', 'arrival_address', 'series', 'number', 'status', 'sunat_code', 'sunat_description',
                'last_error', 'attempts', 'created_at']);

        foreach (['status', 'sales_document_id'] as $param) {
            if ($request->filled($param)) {
                $query->where($param, $request->query($param));
            }
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Reintenta el envío o consulta el estado de una guía.
     *
     * @param string $id Guía.
     * @param FiscalRecordSync $sync Envío de notas y guías.
     */
    public function retry(string $id, FiscalRecordSync $sync): JsonResponse
    {
        $guide = DB::table('fiscal.dispatch_guides')->where('id', $id)->first();
        abort_if($guide === null, 404);

        if ($guide->efact_id !== null) {
            $sync->refresh('guide', $id);
        } else {
            DB::table('fiscal.dispatch_guides')->where('id', $id)->update(['status' => 'queued', 'last_error' => null]);
            SendFiscalRecord::dispatch((string) $guide->tenant_id, 'guide', $id);
        }

        return response()->json(['data' => $this->detail($id)]);
    }

    /**
     * Detalle de una guía.
     *
     * @param string $id Guía.
     * @return array<string, mixed>
     */
    private function detail(string $id): array
    {
        return (array) DB::table('fiscal.dispatch_guides')->where('id', $id)->first();
    }
}
