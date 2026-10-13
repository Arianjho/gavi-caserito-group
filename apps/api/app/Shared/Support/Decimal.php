<?php

declare(strict_types=1);

namespace App\Shared\Support;

/**
 * Ayuda para trabajar con decimales exactos (bcmath) desde valores de base de datos.
 */
final class Decimal
{
    /**
     * Convierte un valor numérico de base de datos a texto decimal.
     *
     * @param mixed $value Valor leído (cadena numérica, entero o decimal).
     * @return numeric-string
     */
    public static function of(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '0';

        return is_numeric($text) ? $text : '0';
    }
}
