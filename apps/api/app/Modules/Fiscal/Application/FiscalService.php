<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Orquesta la emisión de comprobantes: encola, envía a eFact de forma
 * idempotente, aplica estados y nunca bloquea la venta original.
 */
final class FiscalService
{
    /**
     * Medios de pago del ERP y su nombre en eFact.
     *
     * @var array<string, string>
     */
    private const PAYMENT_METHODS = [
        'cash' => 'efectivo', 'yape' => 'yape', 'plin' => 'plin', 'card' => 'tarjeta_credito',
        'transfer' => 'transferencia', 'other' => 'otro',
    ];

    /**
     * Tipos de documento de identidad del ERP y su código SUNAT.
     *
     * @var array<string, string>
     */
    private const DOC_TYPES = ['NONE' => '0', 'DNI' => '1', 'CE' => '4', 'RUC' => '6', 'PAS' => '7'];

    /**
     * Crea el servicio.
     *
     * @param FiscalEngine $engine Motor de facturación.
     */
    public function __construct(private readonly FiscalEngine $engine) {}

    /**
     * Registra el comprobante pendiente de envío para una venta.
     *
     * @param string $saleId Venta (nota de venta) confirmada.
     * @param string $cpeType `01` factura o `03` boleta.
     * @return array{id: string, tenant_id: string}
     *
     * @throws SaleException Si la venta no es emitible o falta configuración.
     */
    public function enqueue(string $saleId, string $cpeType): array
    {
        return DB::transaction(function () use ($saleId, $cpeType): array {
            $sale = DB::table('sales.sales_documents')->where('id', $saleId)->lockForUpdate()->first();
            $this->assertEmittable($sale, $cpeType);
            /** @var stdClass $sale */
            $settings = DB::table('fiscal.efact_settings')->where('company_id', $sale->company_id)->first();
            if ($settings === null || ! $settings->enabled) {
                throw new SaleException('fiscal_not_configured', 'La empresa no tiene eFact configurado y activo.', 422);
            }
            if (DB::table('fiscal.documents')->where('sales_document_id', $saleId)->exists()) {
                throw new SaleException('already_emitted', 'La venta ya tiene un comprobante electrónico.', 409);
            }

            $id = (string) Str::uuid();
            DB::table('fiscal.documents')->insert([
                'id' => $id, 'tenant_id' => $sale->tenant_id, 'company_id' => $sale->company_id,
                'sales_document_id' => $saleId, 'cpe_type' => $cpeType,
                'series' => $cpeType === '01' ? $settings->invoice_series : $settings->boleta_series,
                'external_id' => 'caserito:'.$sale->id,
                'created_by' => request()->user()?->getAuthIdentifier(),
                'updated_by' => request()->user()?->getAuthIdentifier(),
            ]);

            return ['id' => $id, 'tenant_id' => (string) $sale->tenant_id];
        });
    }

    /**
     * Envía el comprobante a eFact y registra el resultado.
     *
     * No lanza excepciones: devuelve si conviene reintentar más tarde.
     *
     * @param string $documentId Comprobante local.
     * @return bool `true` si hay que reintentar con espera.
     */
    public function send(string $documentId): bool
    {
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->first();
        if ($doc === null || in_array($doc->status, ['accepted', 'rejected'], true)) {
            return false;
        }

        try {
            $result = $this->engine->emit(
                $this->credentials((string) $doc->company_id),
                (string) $doc->cpe_type,
                $this->payload((string) $doc->sales_document_id, $doc),
            );
        } catch (FiscalException $e) {
            DB::table('fiscal.documents')->where('id', $documentId)->update([
                'attempts' => $doc->attempts + 1, 'last_error' => $e->getMessage(),
                'status' => $e->retryable ? $doc->status : 'error', 'updated_at' => now(),
            ]);

            return $e->retryable;
        }

        DB::table('fiscal.documents')->where('id', $documentId)->update([
            'attempts' => $doc->attempts + 1, 'efact_id' => $result['efact_id'], 'number' => $result['number'],
            'sent_at' => $doc->sent_at ?? now(), 'last_error' => null, 'updated_at' => now(),
        ]);
        $this->applyStatus($documentId, $result['status'], $result['code'], $result['description']);

        return false;
    }

    /**
     * Aplica un estado de eFact (de la respuesta, el webhook o la consulta).
     *
     * @param string $documentId Comprobante local.
     * @param string $efactStatus Estado de eFact (`pendiente`, `enviado`, `aceptado`, `rechazado`...).
     * @param string|null $code Código SUNAT.
     * @param string|null $description Descripción SUNAT.
     */
    public function applyStatus(string $documentId, string $efactStatus, ?string $code, ?string $description): void
    {
        $status = match ($efactStatus) {
            'aceptado' => 'accepted',
            'rechazado' => 'rejected',
            default => 'pending',
        };

        $changes = ['status' => $status, 'sunat_code' => $code, 'sunat_description' => $description, 'updated_at' => now()];
        if ($status !== 'pending') {
            $changes['finished_at'] = now();
        }
        DB::table('fiscal.documents')->where('id', $documentId)->update($changes);
    }

    /**
     * Consulta a eFact los comprobantes sin estado final (respaldo del webhook).
     *
     * @param string $documentId Comprobante local.
     */
    public function refresh(string $documentId): void
    {
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->first();
        if ($doc === null || $doc->efact_id === null || in_array($doc->status, ['accepted', 'rejected'], true)) {
            return;
        }
        try {
            $result = $this->engine->status($this->credentials((string) $doc->company_id), (string) $doc->cpe_type, (string) $doc->efact_id);
        } catch (FiscalException) {
            return;
        }
        $this->applyStatus($documentId, $result['status'], $result['code'], $result['description']);
    }

    /**
     * Descifra las credenciales de la empresa.
     *
     * @param string $companyId Empresa.
     * @return array{base_url: string, api_key: string, api_secret: string}
     */
    private function credentials(string $companyId): array
    {
        $settings = DB::table('fiscal.efact_settings')->where('company_id', $companyId)->first();
        if ($settings === null) {
            throw new FiscalException('La empresa no tiene eFact configurado.', false);
        }

        return [
            'base_url' => (string) $settings->base_url,
            'api_key' => Crypt::decryptString((string) $settings->api_key),
            'api_secret' => Crypt::decryptString((string) $settings->api_secret),
        ];
    }

    /**
     * Valida que la venta pueda convertirse en comprobante.
     *
     * @param stdClass|null $sale Venta bloqueada.
     * @param string $cpeType `01` o `03`.
     */
    private function assertEmittable(?stdClass $sale, string $cpeType): void
    {
        if ($sale === null) {
            throw new SaleException('not_found', 'Venta no encontrada.', 404);
        }
        if ($sale->status !== 'confirmed') {
            throw new SaleException('document_voided', 'La venta está anulada.', 409);
        }
        $party = $sale->party_id === null ? null : DB::table('cat.parties')->where('id', $sale->party_id)->first();

        if ($cpeType === '01') {
            $this->assertInvoiceCustomer($party);

            return;
        }
        $needsId = bccomp(Decimal::of($sale->total), '700', 2) >= 0;
        if ($needsId && ($party === null || $party->doc_type === 'NONE')) {
            throw new SaleException('customer_id_required', 'Una boleta de S/ 700 o más requiere identificar al cliente.', 422);
        }
    }

    /**
     * Una factura exige un cliente con RUC.
     *
     * @param stdClass|null $party Tercero de la venta.
     */
    private function assertInvoiceCustomer(?stdClass $party): void
    {
        if ($party === null || $party->doc_type !== 'RUC') {
            throw new SaleException('ruc_required', 'La factura requiere un cliente con RUC.', 422);
        }
    }

    /**
     * Arma el cuerpo del comprobante para eFact.
     *
     * @param string $saleId Venta.
     * @param stdClass $doc Comprobante local.
     * @return array<string, mixed>
     */
    private function payload(string $saleId, stdClass $doc): array
    {
        $sale = DB::table('sales.sales_documents')->where('id', $saleId)->first();
        if ($sale === null) {
            throw new FiscalException('La venta de origen ya no existe.', false);
        }
        $party = $sale->party_id === null ? null : DB::table('cat.parties')->where('id', $sale->party_id)->first();
        $credit = $sale->payment_condition === 'credit';

        $payload = [
            'external_id' => $doc->external_id,
            'serie' => $doc->series,
            'fecha_emision' => substr((string) $sale->issued_at, 0, 10),
            'tipo_moneda' => 'PEN',
            'forma_pago' => $credit ? 'Credito' : 'Contado',
            'cliente' => $this->customer($party),
            'items' => $this->items($saleId),
        ];
        if ($credit) {
            $payload['fecha_vencimiento'] = $sale->due_date;
        } else {
            $payload['pagos'] = $this->payments($saleId);
        }

        return $payload;
    }

    /**
     * Datos del cliente en formato eFact.
     *
     * @param stdClass|null $party Tercero de la venta.
     * @return array<string, string>
     */
    private function customer(?stdClass $party): array
    {
        if ($party === null || $party->doc_type === 'NONE') {
            return ['tipo_doc' => '0', 'num_doc' => '-', 'razon_social' => $party->name ?? 'CLIENTES VARIOS'];
        }

        return [
            'tipo_doc' => self::DOC_TYPES[$party->doc_type] ?? '0', 'num_doc' => (string) $party->doc_number,
            'razon_social' => (string) $party->name, 'direccion' => (string) ($party->address ?? ''),
        ];
    }

    /**
     * Ítems del comprobante.
     *
     * @param string $saleId Venta.
     * @return list<array<string, mixed>>
     */
    private function items(string $saleId): array
    {
        $items = [];
        foreach (DB::table('sales.sales_document_lines')->where('document_id', $saleId)->orderBy('line_no')->get() as $line) {
            $item = [
                'descripcion' => (string) $line->description, 'unidad' => 'NIU',
                'cantidad' => (float) $line->qty, 'precio_unitario' => (float) $line->unit_price,
                'tip_afe_igv' => (string) $line->affectation,
            ];
            if (bccomp(Decimal::of($line->discount_pct), '0', 3) > 0) {
                $item['descuentos'] = [['cod_tipo' => '00', 'porcentaje' => (float) $line->discount_pct]];
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Pagos del comprobante (omite anticipos de pedido).
     *
     * @param string $saleId Venta.
     * @return list<array{metodo: string, monto: float}>
     */
    private function payments(string $saleId): array
    {
        $payments = [];
        foreach (DB::table('sales.document_payments')->where('document_id', $saleId)->get() as $payment) {
            $payments[] = [
                'metodo' => self::PAYMENT_METHODS[$payment->method] ?? 'otro',
                'monto' => (float) $payment->amount,
            ];
        }

        return $payments;
    }
}
