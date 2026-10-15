<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Sales\Application\SaleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Guías de remisión electrónicas del remitente (GRE).
 *
 * Se arman desde una venta (ítems y destinatario) o libres, para traslados
 * entre locales o sin venta. El punto de partida sale de la sucursal.
 */
final class DispatchGuideService
{
    /**
     * Registra la guía pendiente de envío.
     *
     * @param array<string, mixed> $data Datos ya validados por el controlador.
     * @return array{id: string, tenant_id: string}
     *
     * @throws SaleException Si faltan datos de origen, destinatario o transporte.
     */
    public function enqueue(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $sale = $this->sale($data['sales_document_id'] ?? null);
            $branchId = (string) ($sale->branch_id ?? $data['branch_id'] ?? '');
            $branch = DB::table('core.branches')->where('id', $branchId)->first();
            if ($branch === null) {
                throw new SaleException('branch_required', 'Indica la sucursal de partida.', 422);
            }
            if (blank($branch->ubigeo) || blank($branch->address)) {
                throw new SaleException('branch_ubigeo_required', 'La sucursal de partida necesita dirección y ubigeo.', 422);
            }
            $company = DB::table('core.companies')->where('id', $branch->company_id)->first();
            $settings = DB::table('fiscal.efact_settings')->where('company_id', $branch->company_id)->first();
            if ($settings === null || ! $settings->enabled) {
                throw new SaleException('fiscal_not_configured', 'La empresa no tiene eFact configurado y activo.', 422);
            }

            $id = (string) Str::uuid();
            $userId = request()->user()?->getAuthIdentifier();
            DB::table('fiscal.dispatch_guides')->insert([
                'id' => $id, 'tenant_id' => $branch->tenant_id, 'company_id' => $branch->company_id,
                'sales_document_id' => $sale->id ?? null, 'shipment_id' => $data['shipment_id'] ?? null,
                'transfer_reason' => $data['transfer_reason'], 'transport_mode' => $data['transport_mode'],
                'transfer_date' => $data['transfer_date'], 'weight' => $data['weight'],
                'weight_unit' => $data['weight_unit'] ?? 'KGM', 'packages' => $data['packages'] ?? null,
                'recipient' => json_encode($this->recipient($data, $sale, $company), JSON_UNESCAPED_UNICODE),
                'departure_ubigeo' => $branch->ubigeo, 'departure_address' => $branch->address,
                'arrival_ubigeo' => $data['arrival_ubigeo'], 'arrival_address' => $data['arrival_address'],
                'transport' => json_encode($this->transport($data), JSON_UNESCAPED_UNICODE),
                'items' => json_encode($this->items($data, $sale), JSON_UNESCAPED_UNICODE),
                'series' => $settings->dispatch_series, 'external_id' => 'caserito-gre:'.$id,
                'created_by' => $userId, 'updated_by' => $userId,
            ]);

            return ['id' => $id, 'tenant_id' => (string) $branch->tenant_id];
        });
    }

    /**
     * Arma el cuerpo de la guía para eFact.
     *
     * @param stdClass $guide Fila de `fiscal.dispatch_guides`.
     * @return array<string, mixed>
     */
    public function payload(stdClass $guide): array
    {
        $recipient = (array) json_decode((string) $guide->recipient, true);
        $transport = (array) json_decode((string) $guide->transport, true);
        $body = [
            'external_id' => $guide->external_id, 'serie' => $guide->series, 'fecha_emision' => now()->toDateString(),
            'destinatario' => [
                'tipo_doc' => $recipient['doc_type'], 'num_doc' => $recipient['doc_number'], 'razon_social' => $recipient['name'],
            ],
            'cod_traslado' => $guide->transfer_reason, 'mod_traslado' => $guide->transport_mode,
            'fecha_traslado' => substr((string) $guide->transfer_date, 0, 10),
            'peso_total' => (float) $guide->weight, 'und_peso_total' => $guide->weight_unit,
            'llegada_ubigeo' => $guide->arrival_ubigeo, 'llegada_direccion' => $guide->arrival_address,
            'partida_ubigeo' => $guide->departure_ubigeo, 'partida_direccion' => $guide->departure_address,
            'items' => (array) json_decode((string) $guide->items, true),
        ];
        if ($guide->packages !== null) {
            $body['num_bultos'] = (int) $guide->packages;
        }

        return $body + $this->transportPart((string) $guide->transport_mode, $transport);
    }

    /**
     * Guías de una venta.
     *
     * @param string $saleId Venta.
     * @return list<stdClass>
     */
    public function forSale(string $saleId): array
    {
        return array_values(DB::table('fiscal.dispatch_guides')->where('sales_document_id', $saleId)->orderByDesc('created_at')->get()->all());
    }

    /**
     * Venta de origen, si la guía parte de una.
     *
     * @param mixed $saleId Identificador de venta o nulo.
     *
     * @throws SaleException Si la venta no existe o está anulada.
     */
    private function sale(mixed $saleId): ?stdClass
    {
        if (! is_string($saleId) || $saleId === '') {
            return null;
        }
        $sale = DB::table('sales.sales_documents')->where('id', $saleId)->first();
        if ($sale === null) {
            throw new SaleException('not_found', 'Venta no encontrada.', 404);
        }
        if ($sale->status !== 'confirmed') {
            throw new SaleException('document_voided', 'La venta está anulada.', 409);
        }

        return $sale;
    }

    /**
     * Destinatario: el informado, el cliente de la venta o la propia empresa (traslado entre locales).
     *
     * @param array<string, mixed> $data Datos de entrada.
     * @param stdClass|null $sale Venta de origen.
     * @param stdClass|null $company Empresa remitente.
     * @return array{doc_type: string, doc_number: string, name: string}
     *
     * @throws SaleException Si no se puede determinar.
     */
    private function recipient(array $data, ?stdClass $sale, ?stdClass $company): array
    {
        $given = $data['recipient'] ?? null;
        if (is_array($given) && ! blank($given['doc_number'] ?? null)) {
            return [
                'doc_type' => PayloadParts::DOC_TYPES[(string) $given['doc_type']] ?? '1',
                'doc_number' => (string) $given['doc_number'], 'name' => (string) $given['name'],
            ];
        }
        if ($data['transfer_reason'] === '04' && $company !== null) {
            return ['doc_type' => '6', 'doc_number' => (string) $company->tax_id, 'name' => (string) $company->legal_name];
        }
        $party = $sale?->party_id === null ? null : DB::table('cat.parties')->where('id', $sale->party_id)->first();
        if ($party === null || $party->doc_type === 'NONE' || ! in_array($party->doc_type, ['DNI', 'RUC'], true)) {
            throw new SaleException('recipient_required', 'Indica el destinatario con DNI o RUC.', 422);
        }

        return ['doc_type' => PayloadParts::DOC_TYPES[$party->doc_type], 'doc_number' => (string) $party->doc_number, 'name' => (string) $party->name];
    }

    /**
     * Datos de transporte según la modalidad.
     *
     * @param array<string, mixed> $data Datos de entrada.
     * @return array<string, mixed>
     *
     * @throws SaleException Si faltan transportista, vehículo o conductor.
     */
    private function transport(array $data): array
    {
        if ($data['transport_mode'] === '01') {
            $carrier = $data['carrier'] ?? null;
            if (! is_array($carrier) || blank($carrier['doc_number'] ?? null)) {
                throw new SaleException('carrier_required', 'El transporte público requiere el RUC del transportista.', 422);
            }

            return ['carrier' => $carrier];
        }
        $driver = $data['driver'] ?? null;
        if (blank($data['vehicle_plate'] ?? null) || ! is_array($driver) || blank($driver['doc_number'] ?? null)) {
            throw new SaleException('driver_required', 'El transporte privado requiere placa y conductor.', 422);
        }

        return ['vehicle_plate' => strtoupper((string) $data['vehicle_plate']), 'driver' => $driver];
    }

    /**
     * Ítems: los de la venta o los informados.
     *
     * @param array<string, mixed> $data Datos de entrada.
     * @param stdClass|null $sale Venta de origen.
     * @return list<array<string, mixed>>
     *
     * @throws SaleException Si no hay ningún ítem.
     */
    private function items(array $data, ?stdClass $sale): array
    {
        $items = [];
        if (is_array($data['items'] ?? null) && $data['items'] !== []) {
            foreach ($data['items'] as $item) {
                $items[] = [
                    'descripcion' => (string) $item['description'], 'cantidad' => (float) $item['qty'],
                    'unidad' => (string) ($item['unit'] ?? 'NIU'),
                ];
            }
        } elseif ($sale !== null) {
            foreach (DB::table('sales.sales_document_lines')->where('document_id', $sale->id)->orderBy('line_no')->get() as $line) {
                $items[] = ['descripcion' => (string) $line->description, 'cantidad' => (float) $line->qty, 'unidad' => 'NIU'];
            }
        }
        if ($items === []) {
            throw new SaleException('items_required', 'La guía necesita al menos un ítem.', 422);
        }

        return $items;
    }

    /**
     * Campos de transporte del cuerpo de eFact.
     *
     * @param string $mode `01` público o `02` privado.
     * @param array<string, mixed> $transport Datos guardados.
     * @return array<string, mixed>
     */
    private function transportPart(string $mode, array $transport): array
    {
        if ($mode === '01') {
            $carrier = (array) $transport['carrier'];

            return ['transportista' => array_filter([
                'tipo_doc' => '6', 'num_doc' => $carrier['doc_number'], 'razon_social' => $carrier['name'] ?? null,
                'nro_mtc' => $carrier['mtc'] ?? null,
            ], fn (mixed $v): bool => $v !== null)];
        }
        $driver = (array) $transport['driver'];

        return [
            'vehiculo' => ['placa' => $transport['vehicle_plate']],
            'conductor' => [
                'tipo_doc' => PayloadParts::DOC_TYPES[(string) ($driver['doc_type'] ?? 'DNI')] ?? '1',
                'num_doc' => $driver['doc_number'], 'tipo' => 'Principal',
                'nombres' => $driver['first_name'] ?? '', 'apellidos' => $driver['last_name'] ?? '',
                'licencia' => $driver['license'] ?? '',
            ],
        ];
    }
}
