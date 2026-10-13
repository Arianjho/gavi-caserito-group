<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Inventory\Application\StockService;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Devolución de mercadería al proveedor: saca el stock, reduce el total de
 * la compra y deja saldo a favor si ya se había pagado de más.
 */
final class PurchaseReturnService
{
    /**
     * Crea el servicio.
     *
     * @param StockService $stock Servicio de stock.
     */
    public function __construct(private readonly StockService $stock) {}

    /**
     * Registra una devolución parcial o total de una compra.
     *
     * @param string $documentId Compra.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Cantidades a devolver por línea.
     * @param string $reason Motivo.
     * @return array<string, mixed>
     */
    public function create(string $documentId, array $lines, string $reason): array
    {
        return DB::transaction(function () use ($documentId, $lines, $reason): array {
            $doc = DB::table('purch.purchase_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($doc === null) {
                throw new SaleException('not_found', 'Compra no encontrada.', 404);
            }
            if ($doc->status !== 'confirmed') {
                throw new SaleException('document_voided', 'La compra está anulada.', 409);
            }

            [$rows, $total] = $this->collect($documentId, $lines);
            $returnId = $this->persist($doc, $rows, $total, $reason);
            DB::table('purch.purchase_documents')->where('id', $documentId)->update([
                'total' => bcsub(Decimal::of($doc->total), $total, 2), 'updated_at' => now(), 'version' => $doc->version + 1,
            ]);

            return (array) DB::table('purch.purchase_returns')->where('id', $returnId)->first();
        });
    }

    /**
     * Valida cantidades y calcula el importe devuelto por línea.
     *
     * @param string $documentId Compra.
     * @param list<array{line_id: string, qty: numeric-string}> $lines Líneas pedidas.
     * @return array{0: list<array{0: stdClass, 1: numeric-string, 2: numeric-string}>, 1: numeric-string}
     */
    private function collect(string $documentId, array $lines): array
    {
        $total = '0';
        $rows = [];
        foreach ($lines as $input) {
            $line = DB::table('purch.purchase_lines')->where('id', $input['line_id'])
                ->where('document_id', $documentId)->lockForUpdate()->first();
            if ($line === null) {
                throw new SaleException('line_not_found', 'Una línea no pertenece a la compra.', 422);
            }
            $returned = Decimal::of(DB::table('purch.purchase_return_lines')->where('purchase_line_id', $line->id)->sum('qty'));
            $left = bcsub(Decimal::of($line->qty), $returned, 3);
            if (bccomp($input['qty'], $left, 3) > 0) {
                throw new SaleException('qty_exceeds_bought', "La cantidad supera lo que se puede devolver ({$left}).", 422);
            }
            $amount = bcadd(bcmul(Decimal::of($line->unit_cost), $input['qty'], 6), '0.005', 2);
            $total = bcadd($total, $amount, 2);
            $rows[] = [$line, $input['qty'], $amount];
        }

        return [$rows, $total];
    }

    /**
     * Inserta la devolución y sus líneas, y saca el stock.
     *
     * @param stdClass $doc Compra bloqueada.
     * @param list<array{0: stdClass, 1: numeric-string, 2: numeric-string}> $rows Líneas, cantidad e importe.
     * @param numeric-string $total Importe total devuelto.
     * @param string $reason Motivo.
     * @return string Identificador de la devolución.
     */
    private function persist(stdClass $doc, array $rows, string $total, string $reason): string
    {
        $returnId = (string) Str::uuid();
        $userId = request()->user()?->getAuthIdentifier();
        DB::table('purch.purchase_returns')->insert([
            'id' => $returnId, 'tenant_id' => $doc->tenant_id, 'document_id' => $doc->id,
            'reason' => $reason, 'total' => $total, 'created_by' => $userId, 'updated_by' => $userId,
        ]);
        foreach ($rows as [$line, $qty, $amount]) {
            DB::table('purch.purchase_return_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $doc->tenant_id, 'return_id' => $returnId,
                'purchase_line_id' => $line->id, 'qty' => $qty, 'total' => $amount,
            ]);
            $this->stock->post($doc->warehouse_id, $line->variant_id, 'purchase_return', $qty, null, [
                'source_type' => 'purchase_return', 'source_id' => $returnId, 'reason' => $reason,
            ]);
        }

        return $returnId;
    }
}
