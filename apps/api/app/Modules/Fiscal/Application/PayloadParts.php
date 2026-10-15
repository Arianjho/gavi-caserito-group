<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Shared\Support\Decimal;
use stdClass;

/**
 * Fragmentos del cuerpo que eFact espera, compartidos por facturas, boletas
 * y notas de crédito.
 */
final class PayloadParts
{
    /**
     * Tipos de documento de identidad del ERP y su código SUNAT.
     *
     * @var array<string, string>
     */
    public const DOC_TYPES = ['NONE' => '0', 'DNI' => '1', 'CE' => '4', 'RUC' => '6', 'PAS' => '7'];

    /**
     * Datos del cliente en formato eFact.
     *
     * @param stdClass|null $party Tercero de la venta.
     * @return array<string, string>
     */
    public static function customer(?stdClass $party): array
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
     * Ítem de comprobante a partir de una línea de venta.
     *
     * @param stdClass $line Línea de la venta.
     * @param string $qty Cantidad que lleva el ítem.
     * @return array<string, mixed>
     */
    public static function item(stdClass $line, string $qty): array
    {
        $item = [
            'descripcion' => (string) $line->description, 'unidad' => 'NIU',
            'cantidad' => (float) $qty, 'precio_unitario' => (float) $line->unit_price,
            'tip_afe_igv' => (string) $line->affectation,
        ];
        if (bccomp(Decimal::of($line->discount_pct), '0', 3) > 0) {
            $item['descuentos'] = [['cod_tipo' => '00', 'porcentaje' => (float) $line->discount_pct]];
        }

        return $item;
    }

    /**
     * Traduce el estado de eFact al estado local.
     *
     * @param string $efactStatus Estado de eFact (`pendiente`, `enviado`, `aceptado`, `rechazado`...).
     * @return string `accepted`, `rejected` o `pending`.
     */
    public static function status(string $efactStatus): string
    {
        return match ($efactStatus) {
            'aceptado' => 'accepted',
            'rechazado' => 'rejected',
            default => 'pending',
        };
    }
}
