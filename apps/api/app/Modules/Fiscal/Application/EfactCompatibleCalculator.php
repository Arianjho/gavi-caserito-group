<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

/**
 * Calculador de importes que replica el algoritmo de eFact (ver §19 del modelo de datos).
 *
 * Usa aritmética decimal (bcmath) y redondeo "mitad hacia afuera", igual que
 * el `round()` de PHP que emplea eFact, para obtener los mismos centavos.
 *
 * Entradas por línea: precio unitario **con** IGV, cantidad, descuento en
 * porcentaje y afectación (Catálogo 07). Para afectaciones 20, 30 y 40 el
 * precio no incluye IGV; las gratuitas (11-16, 21, 31-36) valen cero.
 */
final class EfactCompatibleCalculator
{
    /**
     * Calcula una línea de documento.
     *
     * @param numeric-string $unitPrice Precio unitario con IGV (para gravadas).
     * @param numeric-string $qty Cantidad.
     * @param numeric-string $discountPct Porcentaje de descuento (0 a 100).
     * @param string $affectation Código de afectación IGV (Catálogo 07).
     * @param numeric-string $igvRate Tasa de IGV (por ejemplo `0.18`).
     * @return array{unit_value: numeric-string, discount: numeric-string, sale_value: numeric-string, igv: numeric-string, total: numeric-string}
     */
    public function line(
        string $unitPrice,
        string $qty,
        string $discountPct = '0',
        string $affectation = '10',
        string $igvRate = '0.18',
    ): array {
        if ($this->isFree($affectation)) {
            return ['unit_value' => '0.0000000000', 'discount' => '0.00', 'sale_value' => '0.00', 'igv' => '0.00', 'total' => '0.00'];
        }

        $taxed = $affectation[0] === '1' && (int) $affectation === 10;
        $unitValue = $taxed ? $this->round(bcdiv($unitPrice, bcadd('1', $igvRate, 6), 12), 10) : $this->round($unitPrice, 10);

        $factor = $this->round(bcdiv($discountPct, '100', 8), 6);
        $base = $this->round(bcmul($unitValue, $qty, 12), 2);
        $discountBase = $this->round(bcmul($base, $factor, 8), 2);
        $saleValue = $this->round(bcsub(bcmul($unitValue, $qty, 12), $discountBase, 12), 2);

        if (! $taxed) {
            return [
                'unit_value' => $unitValue, 'discount' => $discountBase,
                'sale_value' => $saleValue, 'igv' => '0.00', 'total' => $saleValue,
            ];
        }

        $gross = $this->round(bcmul($unitPrice, $qty, 8), 2);
        $discountWithTax = $this->round(bcmul($gross, $factor, 8), 2);
        $totalWithTax = bcsub($gross, $discountWithTax, 2);
        $igv = $this->round(bcsub($totalWithTax, $saleValue, 4), 2);

        return [
            'unit_value' => $unitValue, 'discount' => $discountBase,
            'sale_value' => $saleValue, 'igv' => $igv, 'total' => $totalWithTax,
        ];
    }

    /**
     * Suma las líneas calculadas en los totales del documento.
     *
     * @param list<array{affectation: string, sale_value: numeric-string, igv: numeric-string, total: numeric-string}> $lines Líneas ya calculadas.
     * @return array{taxable: numeric-string, exempt: numeric-string, unaffected: numeric-string, export: numeric-string, igv: numeric-string, total: numeric-string}
     */
    public function document(array $lines): array
    {
        /** @var array<string, numeric-string> $totals */
        $totals = ['taxable' => '0.00', 'exempt' => '0.00', 'unaffected' => '0.00', 'export' => '0.00', 'igv' => '0.00'];

        foreach ($lines as $line) {
            $group = match ((int) $line['affectation']) {
                10 => 'taxable',
                20 => 'exempt',
                30 => 'unaffected',
                40 => 'export',
                default => null,
            };
            if ($group !== null) {
                $totals[$group] = bcadd($totals[$group], $line['sale_value'], 2);
            }
            $totals['igv'] = bcadd($totals['igv'], $line['igv'], 2);
        }

        $net = bcadd(bcadd(bcadd($totals['taxable'], $totals['exempt'], 2), $totals['unaffected'], 2), $totals['export'], 2);
        $total = bcadd($net, $totals['igv'], 2);

        return [
            'taxable' => $totals['taxable'],
            'exempt' => $totals['exempt'],
            'unaffected' => $totals['unaffected'],
            'export' => $totals['export'],
            'igv' => $totals['igv'],
            'total' => bccomp($total, '0', 2) < 0 ? '0.00' : $total,
        ];
    }

    /**
     * Indica si la afectación es gratuita (valor cero).
     *
     * @param string $affectation Código de afectación.
     */
    private function isFree(string $affectation): bool
    {
        $code = (int) $affectation;

        return ($code >= 11 && $code <= 16) || $code === 21 || ($code >= 31 && $code <= 36);
    }

    /**
     * Redondea un decimal a `$scale` posiciones, mitad hacia afuera de cero.
     *
     * @param numeric-string $value Valor a redondear.
     * @param int $scale Decimales de salida.
     * @return numeric-string
     */
    private function round(string $value, int $scale): string
    {
        $half = bcdiv('5', bcpow('10', (string) ($scale + 1)), $scale + 1);

        return str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);
    }
}
