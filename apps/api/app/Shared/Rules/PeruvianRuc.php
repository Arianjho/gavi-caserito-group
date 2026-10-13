<?php

declare(strict_types=1);

namespace App\Shared\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Valida un RUC peruano: 11 dígitos, prefijo válido y dígito verificador (módulo 11).
 */
final class PeruvianRuc implements ValidationRule
{
    /**
     * Pesos del algoritmo de SUNAT para los primeros 10 dígitos.
     *
     * @var list<int>
     */
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    /**
     * Prefijos de tipo de contribuyente aceptados.
     *
     * @var list<string>
     */
    private const PREFIXES = ['10', '15', '16', '17', '20'];

    /**
     * Indica si el valor es un RUC válido.
     *
     * @param string $value Valor a comprobar.
     */
    public static function isValid(string $value): bool
    {
        if (preg_match('/^\d{11}$/', $value) !== 1) {
            return false;
        }
        if (! in_array(substr($value, 0, 2), self::PREFIXES, true)) {
            return false;
        }

        $sum = 0;
        foreach (self::WEIGHTS as $index => $weight) {
            $sum += (int) $value[$index] * $weight;
        }
        $check = (11 - ($sum % 11)) % 10;

        return $check === (int) $value[10];
    }

    /**
     * Ejecuta la validación de Laravel.
     *
     * @param string $attribute Nombre del campo.
     * @param mixed $value Valor recibido.
     * @param Closure(string, string|null=): PotentiallyTranslatedString $fail Función para reportar el error.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('El RUC no es válido: debe tener 11 dígitos y pasar la verificación de SUNAT.');
        }
    }
}
