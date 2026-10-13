<?php

declare(strict_types=1);

use App\Modules\Fiscal\Application\EfactCompatibleCalculator;

/**
 * Casos de oro del calculador compatible con eFact.
 */
beforeEach(function (): void {
    $this->calc = new EfactCompatibleCalculator;
});

it('desglosa IGV de un precio gravado simple', function (): void {
    $line = $this->calc->line('118', '1');

    expect($line['sale_value'])->toBe('100.00')
        ->and($line['igv'])->toBe('18.00')
        ->and($line['total'])->toBe('118.00');
});

it('calcula el IGV por diferencia en cantidades que generan redondeo', function (): void {
    // 3 x 10.00 con IGV: valor unitario 8.4745762712, valor de venta 25.42, IGV 4.58.
    $line = $this->calc->line('10', '3');

    expect($line['unit_value'])->toBe('8.4745762712')
        ->and($line['sale_value'])->toBe('25.42')
        ->and($line['igv'])->toBe('4.58')
        ->and($line['total'])->toBe('30.00');
});

it('aplica descuento porcentual por linea', function (): void {
    // 2 x 59.00 con 10 % de descuento: bruto 118.00, descuento 11.80, total 106.20.
    $line = $this->calc->line('59', '2', '10');

    expect($line['total'])->toBe('106.20')
        ->and($line['sale_value'])->toBe('90.00')
        ->and($line['igv'])->toBe('16.20');
});

it('no calcula IGV para exonerados', function (): void {
    $line = $this->calc->line('50', '2', '0', '20');

    expect($line['sale_value'])->toBe('100.00')->and($line['igv'])->toBe('0.00')->and($line['total'])->toBe('100.00');
});

it('las lineas gratuitas valen cero', function (): void {
    $line = $this->calc->line('50', '2', '0', '13');

    expect($line['total'])->toBe('0.00')->and($line['igv'])->toBe('0.00');
});

it('suma los totales del documento por afectacion', function (): void {
    $a = $this->calc->line('118', '1') + ['affectation' => '10'];
    $b = $this->calc->line('50', '2', '0', '20') + ['affectation' => '20'];

    $doc = $this->calc->document([$a, $b]);

    expect($doc['taxable'])->toBe('100.00')
        ->and($doc['exempt'])->toBe('100.00')
        ->and($doc['igv'])->toBe('18.00')
        ->and($doc['total'])->toBe('218.00');
});

it('maneja cantidades fraccionarias', function (): void {
    // 0.5 kg a 23.60 con IGV: 11.80 total, 10.00 base, 1.80 IGV.
    $line = $this->calc->line('23.60', '0.5');

    expect($line['total'])->toBe('11.80')->and($line['sale_value'])->toBe('10.00')->and($line['igv'])->toBe('1.80');
});
