<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Sales\Application\SaleException;
use App\Modules\Sales\Application\SaleReturnService;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Notas de crédito (tipo 07) sobre comprobantes aceptados por SUNAT.
 *
 * Dos modos según el motivo del catálogo 09:
 * - por ítems (01, 02, 03, 06, 09): revierte líneas de la venta, total o parcial;
 * - por monto (04, 05, 07, 08): descuento o bonificación posterior.
 *
 * Opcionalmente reingresa el stock y devuelve el dinero (motivos 01 y 06).
 */
final class CreditNoteService
{
    /**
     * Motivos que revierten toda la operación.
     *
     * @var list<string>
     */
    private const FULL_REASONS = ['01', '02', '03'];

    /**
     * Motivos que se expresan como un monto, no como ítems.
     *
     * @var list<string>
     */
    public const AMOUNT_REASONS = ['04', '05', '07', '08'];

    /**
     * Motivos que admiten reingresar stock.
     *
     * @var list<string>
     */
    private const STOCK_REASONS = ['01', '06'];

    /**
     * Estados que consumen el saldo acreditable del comprobante.
     *
     * @var list<string>
     */
    private const ACTIVE = ['queued', 'pending', 'accepted'];

    /**
     * Crea el servicio.
     *
     * @param SaleReturnService $returns Devoluciones de venta.
     */
    public function __construct(private readonly SaleReturnService $returns) {}

    /**
     * Registra la nota de crédito pendiente de envío.
     *
     * @param string $documentId Comprobante que se modifica.
     * @param string $reasonCode Motivo (catálogo 09).
     * @param string $reasonText Descripción del motivo.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Ítems a revertir (vacío = todo lo pendiente).
     * @param numeric-string|null $amount Monto con IGV para motivos por monto.
     * @param string|null $refundMethod Si se informa, reingresa stock y devuelve el dinero con este medio.
     * @return array{id: string, tenant_id: string}
     *
     * @throws SaleException Si el comprobante no admite la nota o los datos no cuadran.
     */
    public function enqueue(
        string $documentId,
        string $reasonCode,
        string $reasonText,
        array $lines = [],
        ?string $amount = null,
        ?string $refundMethod = null,
    ): array {
        return DB::transaction(function () use ($documentId, $reasonCode, $reasonText, $lines, $amount, $refundMethod): array {
            $doc = $this->lockDocument($documentId);
            $settings = DB::table('fiscal.efact_settings')->where('company_id', $doc->company_id)->first();
            if ($settings === null || ! $settings->enabled) {
                throw new SaleException('fiscal_not_configured', 'La empresa no tiene eFact configurado y activo.', 422);
            }
            [$snapshot, $total, $returnLines] = $this->build($doc, $reasonCode, $reasonText, $lines, $amount, $refundMethod);

            $id = (string) Str::uuid();
            $userId = request()->user()?->getAuthIdentifier();
            DB::table('fiscal.credit_notes')->insert([
                'id' => $id, 'tenant_id' => $doc->tenant_id, 'company_id' => $doc->company_id, 'document_id' => $documentId,
                'reason_code' => $reasonCode, 'reason_text' => $reasonText,
                'lines' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'total' => $total,
                'series' => $doc->cpe_type === '01' ? $settings->credit_invoice_series : $settings->credit_boleta_series,
                'external_id' => 'caserito-nc:'.$id, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            if ($refundMethod !== null) {
                $return = $this->returns->create((string) $doc->sales_document_id, $returnLines, $refundMethod, $reasonText, true);
                DB::table('fiscal.credit_notes')->where('id', $id)->update(['sale_return_id' => $return['id']]);
            }

            return ['id' => $id, 'tenant_id' => (string) $doc->tenant_id];
        });
    }

    /**
     * Arma el cuerpo de la nota para eFact.
     *
     * @param stdClass $note Fila de `fiscal.credit_notes`.
     * @return array<string, mixed>
     */
    public function payload(stdClass $note): array
    {
        $doc = DB::table('fiscal.documents')->where('id', $note->document_id)->first();
        if ($doc === null) {
            throw new FiscalException('El comprobante de origen ya no existe.', false);
        }
        $sale = DB::table('sales.sales_documents')->where('id', $doc->sales_document_id)->first();
        $party = $sale?->party_id === null ? null : DB::table('cat.parties')->where('id', $sale->party_id)->first();

        return [
            'external_id' => $note->external_id, 'serie' => $note->series, 'fecha_emision' => now()->toDateString(),
            'tipo_moneda' => 'PEN', 'cliente' => PayloadParts::customer($party),
            'doc_afectado_tipo' => $doc->cpe_type, 'doc_afectado_serie' => $doc->series,
            'doc_afectado_correlativo' => (string) $doc->number,
            'cod_motivo' => $note->reason_code, 'des_motivo' => $note->reason_text,
            'items' => $this->items($note, (string) ($sale->id ?? '')),
        ];
    }

    /**
     * Notas de un comprobante.
     *
     * @param string $documentId Comprobante.
     * @return list<stdClass>
     */
    public function forDocument(string $documentId): array
    {
        return array_values(DB::table('fiscal.credit_notes')->where('document_id', $documentId)->orderByDesc('created_at')->get()->all());
    }

    /**
     * Ítems, importe y líneas a devolver según el modo de la nota.
     *
     * @param stdClass $doc Comprobante.
     * @param string $reasonCode Motivo.
     * @param string $reasonText Descripción.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Líneas pedidas.
     * @param numeric-string|null $amount Monto para motivos por monto.
     * @param string|null $refundMethod Medio de devolución, si reingresa stock.
     * @return array{0: list<array<string, string>>, 1: numeric-string, 2: list<array{line_id: string, qty: numeric-string}>}
     *
     * @throws SaleException Si el motivo no admite devolver stock.
     */
    private function build(stdClass $doc, string $reasonCode, string $reasonText, array $lines, ?string $amount, ?string $refundMethod): array
    {
        if (in_array($reasonCode, self::AMOUNT_REASONS, true)) {
            if ($refundMethod !== null) {
                throw new SaleException('restock_not_allowed', 'Solo los motivos 01 y 06 permiten reingresar stock.', 422);
            }
            [$snapshot, $total] = $this->amountSnapshot($doc, $reasonText, $amount);

            return [$snapshot, $total, []];
        }
        [$snapshot, $total] = $this->lineSnapshot($doc, $reasonCode, $lines);
        if ($refundMethod !== null && ! in_array($reasonCode, self::STOCK_REASONS, true)) {
            throw new SaleException('restock_not_allowed', 'Solo los motivos 01 y 06 permiten reingresar stock.', 422);
        }
        $returnLines = array_map(fn (array $row): array => ['line_id' => $row['line_id'], 'qty' => $row['qty']], $snapshot);

        return [$snapshot, $total, $returnLines];
    }

    /**
     * Bloquea el comprobante y verifica que admita notas.
     *
     * @param string $documentId Comprobante.
     *
     * @throws SaleException Si no existe, no está aceptado o tiene una baja en curso.
     */
    private function lockDocument(string $documentId): stdClass
    {
        $doc = DB::table('fiscal.documents')->where('id', $documentId)->lockForUpdate()->first();
        if ($doc === null) {
            throw new SaleException('not_found', 'Comprobante no encontrado.', 404);
        }
        if ($doc->status !== 'accepted') {
            throw new SaleException('document_not_accepted', 'Solo un comprobante aceptado por SUNAT admite nota de crédito.', 409);
        }
        if ($doc->void_status === 'pending') {
            throw new SaleException('void_in_progress', 'El comprobante tiene una baja en curso.', 409);
        }

        return $doc;
    }

    /**
     * Ítems a revertir y su importe, por líneas de la venta.
     *
     * @param stdClass $doc Comprobante.
     * @param string $reasonCode Motivo.
     * @param list<array{line_id: string, qty: numeric-string}> $requested Líneas pedidas (vacío = todo).
     * @return array{0: list<array{line_id: string, qty: numeric-string, total: numeric-string}>, 1: numeric-string}
     */
    private function lineSnapshot(stdClass $doc, string $reasonCode, array $requested): array
    {
        $credited = $this->credited((string) $doc->id);
        $remaining = $this->remaining((string) $doc->sales_document_id, $credited);
        if ($remaining === []) {
            throw new SaleException('nothing_to_credit', 'El comprobante ya fue acreditado por completo.', 409);
        }
        $full = in_array($reasonCode, self::FULL_REASONS, true);
        if ($full && $credited !== []) {
            throw new SaleException('already_credited', 'El comprobante ya tiene notas de crédito; usa un motivo parcial.', 409);
        }
        $picked = $requested === [] || $full
            ? array_map(fn (array $r): array => ['line_id' => $r['line']->id, 'qty' => $r['left']], array_values($remaining))
            : $requested;
        if ($full && $requested !== [] && ! $this->coversAll($requested, $remaining)) {
            throw new SaleException('full_credit_required', 'Este motivo exige revertir todos los ítems.', 422);
        }

        return $this->price($picked, $remaining);
    }

    /**
     * Importe de cada línea pedida, validando contra lo que queda por acreditar.
     *
     * @param list<array{line_id: string, qty: numeric-string}> $picked Líneas y cantidades.
     * @param array<string, array{line: stdClass, left: numeric-string, left_total: numeric-string}> $remaining Saldo por línea.
     * @return array{0: list<array{line_id: string, qty: numeric-string, total: numeric-string}>, 1: numeric-string}
     */
    private function price(array $picked, array $remaining): array
    {
        $snapshot = [];
        $total = '0';
        foreach ($picked as $input) {
            $row = $remaining[$input['line_id']] ?? null;
            if ($row === null) {
                throw new SaleException('line_not_found', 'Una línea no pertenece al comprobante o ya fue acreditada.', 422);
            }
            if (bccomp($input['qty'], '0', 3) <= 0 || bccomp($input['qty'], $row['left'], 3) > 0) {
                throw new SaleException('qty_exceeds_sold', "La cantidad supera lo que se puede acreditar ({$row['left']}).", 422);
            }
            $share = bccomp($input['qty'], $row['left'], 3) === 0
                ? $row['left_total']
                : bcadd(bcmul(Decimal::of($row['line']->total), bcdiv($input['qty'], Decimal::of($row['line']->qty), 10), 6), '0.005', 2);
            $snapshot[] = ['line_id' => $input['line_id'], 'qty' => $input['qty'], 'total' => $share];
            $total = bcadd($total, $share, 2);
        }

        return [$snapshot, $total];
    }

    /**
     * Indica si lo pedido cubre exactamente todo lo pendiente.
     *
     * @param list<array{line_id: string, qty: numeric-string}> $requested Líneas pedidas.
     * @param array<string, array{line: stdClass, left: numeric-string, left_total: numeric-string}> $remaining Saldo por línea.
     */
    private function coversAll(array $requested, array $remaining): bool
    {
        $byLine = [];
        foreach ($requested as $r) {
            $byLine[$r['line_id']] = $r['qty'];
        }
        foreach ($remaining as $id => $row) {
            if (! isset($byLine[$id]) || bccomp($byLine[$id], $row['left'], 3) !== 0) {
                return false;
            }
        }

        return count($byLine) === count($remaining);
    }

    /**
     * Nota por monto (descuento o bonificación posterior).
     *
     * @param stdClass $doc Comprobante.
     * @param string $reasonText Descripción.
     * @param numeric-string|null $amount Monto con IGV.
     * @return array{0: list<array{amount: numeric-string, description: string}>, 1: numeric-string}
     */
    private function amountSnapshot(stdClass $doc, string $reasonText, ?string $amount): array
    {
        if ($amount === null || bccomp($amount, '0', 2) <= 0) {
            throw new SaleException('amount_required', 'Indica el monto de la nota de crédito.', 422);
        }
        $sale = DB::table('sales.sales_documents')->where('id', $doc->sales_document_id)->first();
        $used = Decimal::of(DB::table('fiscal.credit_notes')->where('document_id', $doc->id)->whereIn('status', self::ACTIVE)->sum('total'));
        $left = bcsub(Decimal::of($sale->total ?? 0), $used, 2);
        if (bccomp($amount, $left, 2) > 0) {
            throw new SaleException('amount_exceeds_document', "El monto supera lo que se puede acreditar (S/ {$left}).", 422);
        }

        return [[['amount' => bcadd($amount, '0', 2), 'description' => $reasonText]], bcadd($amount, '0', 2)];
    }

    /**
     * Cantidad ya acreditada por línea en notas vigentes.
     *
     * @param string $documentId Comprobante.
     * @return array<string, numeric-string> Cantidad por línea.
     */
    private function credited(string $documentId): array
    {
        $by = [];
        $notes = DB::table('fiscal.credit_notes')->where('document_id', $documentId)->whereIn('status', self::ACTIVE)->pluck('lines');
        foreach ($notes as $json) {
            foreach ((array) json_decode((string) $json, true) as $row) {
                if (isset($row['line_id'])) {
                    $by[$row['line_id']] = bcadd($by[$row['line_id']] ?? '0', Decimal::of($row['qty']), 3);
                }
            }
        }

        return $by;
    }

    /**
     * Cantidad e importe que aún se puede acreditar por línea.
     *
     * @param string $saleId Venta de origen.
     * @param array<string, numeric-string> $credited Cantidad ya acreditada por línea.
     * @return array<string, array{line: stdClass, left: numeric-string, left_total: numeric-string}>
     */
    private function remaining(string $saleId, array $credited): array
    {
        $remaining = [];
        foreach (DB::table('sales.sales_document_lines')->where('document_id', $saleId)->orderBy('line_no')->get() as $line) {
            $left = bcsub(Decimal::of($line->qty), $credited[$line->id] ?? '0', 3);
            if (bccomp($left, '0', 3) <= 0) {
                continue;
            }
            $done = $this->creditedTotal((string) $line->id);
            $remaining[(string) $line->id] = [
                'line' => $line, 'left' => $left, 'left_total' => bcsub(Decimal::of($line->total), $done, 2),
            ];
        }

        return $remaining;
    }

    /**
     * Importe ya acreditado de una línea en notas vigentes.
     *
     * @param string $lineId Línea de venta.
     * @return numeric-string
     */
    private function creditedTotal(string $lineId): string
    {
        $total = '0';
        $notes = DB::table('fiscal.credit_notes')->whereIn('status', self::ACTIVE)
            ->whereRaw('lines @> ?::jsonb', [json_encode([['line_id' => $lineId]])])->pluck('lines');
        foreach ($notes as $json) {
            foreach ((array) json_decode((string) $json, true) as $row) {
                if (($row['line_id'] ?? null) === $lineId) {
                    $total = bcadd($total, Decimal::of($row['total']), 2);
                }
            }
        }

        return $total;
    }

    /**
     * Ítems del cuerpo según el modo de la nota.
     *
     * @param stdClass $note Nota local.
     * @param string $saleId Venta de origen (da la afectación de IGV de las notas por monto).
     * @return list<array<string, mixed>>
     */
    private function items(stdClass $note, string $saleId): array
    {
        $items = [];
        $affectation = (string) (DB::table('sales.sales_document_lines')->where('document_id', $saleId)->orderBy('line_no')->value('affectation') ?? '10');
        foreach ((array) json_decode((string) $note->lines, true) as $row) {
            if (isset($row['amount'])) {
                $items[] = [
                    'descripcion' => (string) $row['description'], 'unidad' => 'ZZ', 'cantidad' => 1,
                    'precio_unitario' => (float) $row['amount'], 'tip_afe_igv' => $affectation,
                ];

                continue;
            }
            $line = DB::table('sales.sales_document_lines')->where('id', $row['line_id'])->first();
            if ($line !== null) {
                $items[] = PayloadParts::item($line, (string) $row['qty']);
            }
        }

        return $items;
    }
}
