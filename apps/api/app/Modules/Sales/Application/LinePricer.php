<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Fiscal\Application\EfactCompatibleCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Calcula las líneas y el total de un documento comercial (cotización o
 * pedido) con las mismas reglas de redondeo que Gavi eFact.
 */
final class LinePricer
{
    /**
     * Crea el calculador.
     *
     * @param EfactCompatibleCalculator $calculator Calculador de importes.
     */
    public function __construct(private readonly EfactCompatibleCalculator $calculator) {}

    /**
     * Calcula líneas y total.
     *
     * @param list<array{variant_id: string, qty: numeric-string, unit_price: numeric-string, discount_pct?: numeric-string|null}> $lines Líneas.
     * @param bool $describe Si se debe resolver la descripción del producto.
     * @return array{0: list<array<string, mixed>>, 1: numeric-string} Líneas y total.
     */
    public function price(array $lines, bool $describe = true): array
    {
        $out = [];
        $docLines = [];
        foreach ($lines as $index => $line) {
            $meta = DB::table('cat.item_variants as v')->join('cat.items as i', 'i.id', '=', 'v.item_id')
                ->where('v.id', $line['variant_id'])
                ->first(['i.name', 'v.name as variant_name', 'i.default_tax_affectation']);
            if ($meta === null) {
                throw new SaleException('variant_not_found', 'Un producto del documento ya no existe.', 422);
            }
            $affectation = (string) ($meta->default_tax_affectation ?? '10');
            $calc = $this->calculator->line($line['unit_price'], $line['qty'], $line['discount_pct'] ?? '0', $affectation);
            $docLines[] = $calc + ['affectation' => $affectation];
            $out[] = $calc + [
                'line_no' => $index + 1, 'variant_id' => $line['variant_id'],
                'description' => $describe ? trim($meta->name.' '.($meta->variant_name ?? '')) : '',
                'qty' => $line['qty'], 'unit_price' => $line['unit_price'],
                'discount_pct' => $line['discount_pct'] ?? '0', 'affectation' => $affectation,
            ];
        }

        return [$out, $this->calculator->document($docLines)['total']];
    }
}
