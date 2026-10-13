<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Cash\Application\CashService;
use App\Modules\Inventory\Application\StockService;
use App\Modules\Platform\Models\Warehouse;
use App\Modules\Sales\Application\SaleException;
use App\Shared\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registra compras (con ingreso de stock a costo), pagos a proveedores y
 * anulaciones.
 */
final class PurchaseService
{
    /**
     * Crea el servicio.
     *
     * @param StockService $stock Servicio de stock.
     * @param CashService $cash Servicio de caja.
     */
    public function __construct(
        private readonly StockService $stock,
        private readonly CashService $cash,
    ) {}

    /**
     * Registra una compra, ingresa el stock y, si es al contado, el pago.
     *
     * @param string $tenantId Cuenta.
     * @param string $warehouseId Almacén que recibe.
     * @param string $supplierId Proveedor.
     * @param list<array{variant_id: string, qty: numeric-string, unit_cost: numeric-string}> $lines Líneas.
     * @param array{supplier_document: string|null, issued_date: string, notes: string|null, due_date: string|null, method: string|null} $header Cabecera; con `due_date` es a crédito, sin `due_date` se paga al contado con `method`.
     * @return array<string, mixed>
     *
     * @throws SaleException Si el crédito no trae vencimiento o falta el medio de pago.
     */
    public function create(string $tenantId, string $warehouseId, string $supplierId, array $lines, array $header): array
    {
        return DB::transaction(function () use ($tenantId, $warehouseId, $supplierId, $lines, $header): array {
            $warehouse = Warehouse::query()->findOrFail($warehouseId);
            $credit = $header['due_date'] !== null;
            if (! $credit && $header['method'] === null) {
                throw new SaleException('payment_method_required', 'Una compra al contado requiere el medio de pago.', 422);
            }

            $total = '0.00';
            $rows = [];
            foreach ($lines as $index => $line) {
                $lineTotal = bcmul($line['qty'], $line['unit_cost'], 2);
                $total = bcadd($total, $lineTotal, 2);
                $rows[] = $line + ['line_no' => $index + 1, 'total' => $lineTotal];
            }

            $id = (string) Str::uuid();
            $userId = request()->user()?->getAuthIdentifier();
            DB::table('purch.purchase_documents')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'company_id' => $warehouse->company_id,
                'branch_id' => $warehouse->branch_id, 'warehouse_id' => $warehouseId, 'supplier_id' => $supplierId,
                'number' => (int) DB::table('purch.purchase_documents')->where('company_id', $warehouse->company_id)->max('number') + 1,
                'supplier_document' => $header['supplier_document'], 'issued_date' => $header['issued_date'],
                'total' => $total, 'payment_condition' => $credit ? 'credit' : 'cash', 'due_date' => $header['due_date'],
                'notes' => $header['notes'], 'created_by' => $userId, 'updated_by' => $userId,
            ]);

            $this->receiveLines($tenantId, $id, $warehouseId, $rows);
            if (! $credit) {
                $this->pay($id, (string) $header['method'], $total, null);
            }

            return $this->find($id);
        });
    }

    /**
     * Registra un pago (total o parcial) a un documento de compra.
     *
     * @param string $documentId Documento.
     * @param string $method Medio de pago.
     * @param numeric-string $amount Importe.
     * @param string|null $reference Referencia.
     * @return array<string, mixed>
     *
     * @throws SaleException Si el documento está anulado o el pago supera el saldo.
     */
    public function pay(string $documentId, string $method, string $amount, ?string $reference): array
    {
        return DB::transaction(function () use ($documentId, $method, $amount, $reference): array {
            $doc = DB::table('purch.purchase_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($doc === null) {
                throw new SaleException('not_found', 'Compra no encontrada.', 404);
            }
            if ($doc->status !== 'confirmed') {
                throw new SaleException('document_voided', 'La compra está anulada.', 409);
            }
            $paid = Decimal::of($doc->paid_total);
            $balance = bcsub(Decimal::of($doc->total), $paid, 2);
            if (bccomp($amount, $balance, 2) > 0) {
                throw new SaleException('amount_exceeds_balance', "El pago supera el saldo pendiente ({$balance}).", 422);
            }

            $userId = request()->user()?->getAuthIdentifier();
            DB::table('purch.purchase_payments')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $doc->tenant_id, 'document_id' => $documentId,
                'method' => $method, 'amount' => $amount, 'reference' => $reference,
                'cash_session_id' => $method === 'cash' ? $this->cash->currentSessionId((string) $userId) : null,
                'created_by' => $userId,
            ]);
            DB::table('purch.purchase_documents')->where('id', $documentId)->update([
                'paid_total' => bcadd($paid, $amount, 2), 'updated_at' => now(), 'version' => $doc->version + 1,
            ]);

            return $this->find($documentId);
        });
    }

    /**
     * Anula una compra y revierte el ingreso de stock.
     *
     * @param string $documentId Documento.
     * @param string $reason Motivo.
     * @return array<string, mixed>
     *
     * @throws SaleException Si ya estaba anulada o tiene pagos registrados.
     */
    public function void(string $documentId, string $reason): array
    {
        return DB::transaction(function () use ($documentId, $reason): array {
            $doc = DB::table('purch.purchase_documents')->where('id', $documentId)->lockForUpdate()->first();
            if ($doc === null) {
                throw new SaleException('not_found', 'Compra no encontrada.', 404);
            }
            if ($doc->status !== 'confirmed') {
                throw new SaleException('already_voided', 'La compra ya estaba anulada.', 409);
            }
            if (bccomp(Decimal::of($doc->paid_total), '0', 2) > 0) {
                throw new SaleException('has_payments', 'La compra tiene pagos; devuélvelos antes de anular.', 409);
            }

            $lines = DB::table('purch.purchase_lines')->where('document_id', $documentId)->get();
            foreach ($lines as $line) {
                $this->stock->post($doc->warehouse_id, $line->variant_id, 'purchase_return', Decimal::of($line->qty), null, [
                    'source_type' => 'purchase', 'source_id' => $documentId, 'reason' => 'Anulación de compra',
                ]);
            }
            DB::table('purch.purchase_documents')->where('id', $documentId)->update([
                'status' => 'voided', 'voided_at' => now(), 'void_reason' => $reason,
                'updated_at' => now(), 'version' => $doc->version + 1,
            ]);

            return $this->find($documentId);
        });
    }

    /**
     * Devuelve un documento con sus líneas y pagos.
     *
     * @param string $documentId Documento.
     * @return array<string, mixed>
     */
    public function find(string $documentId): array
    {
        $doc = DB::table('purch.purchase_documents as d')
            ->join('cat.parties as p', 'p.id', '=', 'd.supplier_id')
            ->where('d.id', $documentId)->first(['d.*', 'p.name as supplier_name']);
        if ($doc === null) {
            throw new SaleException('not_found', 'Compra no encontrada.', 404);
        }

        return (array) $doc + [
            'lines' => DB::table('purch.purchase_lines as l')
                ->join('cat.item_variants as v', 'v.id', '=', 'l.variant_id')
                ->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('l.document_id', $documentId)->orderBy('l.line_no')
                ->selectRaw("l.*, trim(i.name || ' ' || coalesce(v.name, '')) as description, "
                    .'coalesce((select sum(r.qty) from purch.purchase_return_lines r where r.purchase_line_id = l.id), 0) as qty_returned')
                ->get()->all(),
            'payments' => DB::table('purch.purchase_payments')->where('document_id', $documentId)->orderBy('paid_at')->get()->all(),
        ];
    }

    /**
     * Inserta las líneas e ingresa el stock de las variantes que lo controlan.
     *
     * @param string $tenantId Cuenta.
     * @param string $documentId Documento.
     * @param string $warehouseId Almacén.
     * @param list<array<string, mixed>> $rows Líneas calculadas.
     */
    private function receiveLines(string $tenantId, string $documentId, string $warehouseId, array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('purch.purchase_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'document_id' => $documentId,
                'line_no' => $row['line_no'], 'variant_id' => $row['variant_id'], 'qty' => $row['qty'],
                'unit_cost' => $row['unit_cost'], 'total' => $row['total'],
            ]);
            $tracks = (bool) DB::table('cat.item_variants as v')->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('v.id', $row['variant_id'])->value('i.tracks_stock');
            if ($tracks) {
                $this->stock->post($warehouseId, $row['variant_id'], 'purchase', $row['qty'], $row['unit_cost'], [
                    'source_type' => 'purchase', 'source_id' => $documentId,
                ]);
            }
        }
    }
}
