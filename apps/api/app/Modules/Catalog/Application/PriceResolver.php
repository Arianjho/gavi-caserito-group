<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use Illuminate\Support\Facades\DB;

/**
 * Determina el precio unitario de una variante para un cliente y una
 * cantidad: la escala de su lista de precios si la hay y, si no, el
 * precio base de la variante.
 */
final class PriceResolver
{
    /**
     * Resuelve los precios de varias variantes de una vez.
     *
     * @param string|null $partyId Cliente (puede no tener lista).
     * @param list<array{variant_id: string, qty: numeric-string}> $lines Variantes y cantidades.
     * @return list<array{variant_id: string, unit_price: string|null, source: string}>
     */
    public function resolve(?string $partyId, array $lines): array
    {
        $listId = $partyId === null ? null : DB::table('cat.parties')->where('id', $partyId)->value('price_list_id');
        $out = [];

        foreach ($lines as $line) {
            $price = $listId === null ? null : $this->listPrice((string) $listId, $line['variant_id'], $line['qty']);
            if ($price !== null) {
                $out[] = ['variant_id' => $line['variant_id'], 'unit_price' => $price, 'source' => 'list'];

                continue;
            }
            $base = DB::table('cat.item_variants')->where('id', $line['variant_id'])->value('sale_price');
            $out[] = [
                'variant_id' => $line['variant_id'],
                'unit_price' => $base === null ? null : (string) $base,
                'source' => 'base',
            ];
        }

        return $out;
    }

    /**
     * Precio de la escala más alta cuya cantidad mínima se alcanza.
     *
     * @param string $listId Lista de precios.
     * @param string $variantId Variante.
     * @param numeric-string $qty Cantidad pedida.
     */
    private function listPrice(string $listId, string $variantId, string $qty): ?string
    {
        $price = DB::table('cat.price_list_items')->where('price_list_id', $listId)
            ->where('variant_id', $variantId)->where('min_qty', '<=', $qty)
            ->orderByDesc('min_qty')->value('price');

        return $price === null ? null : (string) $price;
    }
}
