<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Sales\Application\SaleException;
use Illuminate\Support\Facades\DB;

/**
 * Une dos terceros duplicados: el historial del duplicado pasa al que se
 * conserva y el duplicado queda como eliminado (no se borra físicamente).
 */
final class PartyMerger
{
    /** Tablas y columnas que referencian a un tercero. */
    private const REFERENCES = [
        ['sales.sales_documents', 'party_id'],
        ['sales.orders', 'party_id'],
        ['sales.quotations', 'party_id'],
        ['ship.shipments', 'party_id'],
        ['notif.messages', 'party_id'],
        ['purch.purchase_documents', 'supplier_id'],
        ['purch.expenses', 'supplier_id'],
    ];

    /**
     * Fusiona `$sourceId` dentro de `$targetId`.
     *
     * @param string $sourceId Tercero duplicado (se elimina lógicamente).
     * @param string $targetId Tercero que se conserva.
     * @return array<string, int> Cantidad de registros movidos por tabla.
     *
     * @throws SaleException Si no existen, son el mismo o ya están eliminados.
     */
    public function merge(string $sourceId, string $targetId): array
    {
        if ($sourceId === $targetId) {
            throw new SaleException('same_party', 'Elige dos registros distintos para unir.', 422);
        }

        return DB::transaction(function () use ($sourceId, $targetId): array {
            $rows = DB::table('cat.parties')->whereIn('id', [$sourceId, $targetId])
                ->where('status', '<>', 'deleted')->lockForUpdate()->get()->keyBy('id');
            $source = $rows->get($sourceId);
            $target = $rows->get($targetId);
            if ($source === null || $target === null) {
                throw new SaleException('not_found', 'No se encontró alguno de los registros.', 404);
            }

            $moved = [];
            foreach (self::REFERENCES as [$table, $column]) {
                $moved[$table] = DB::table($table)->where($column, $sourceId)->update([$column => $targetId]);
            }
            DB::table('cat.parties')->where('id', $targetId)->update([
                'is_customer' => $target->is_customer || $source->is_customer,
                'is_supplier' => $target->is_supplier || $source->is_supplier,
                'updated_at' => now(),
            ]);
            DB::table('cat.parties')->where('id', $sourceId)->update([
                'status' => 'deleted', 'doc_number' => null, 'updated_at' => now(),
                'notes' => trim((string) $source->notes.' [Unido a otro registro]'),
            ]);

            return $moved;
        });
    }
}
