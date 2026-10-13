<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use RuntimeException;

/**
 * La salida solicitada supera las existencias y el almacén no permite stock negativo.
 */
final class StockInsufficientException extends RuntimeException
{
    /**
     * Crea la excepción.
     *
     * @param string $variantId Variante sin stock suficiente.
     * @param string $available Existencias disponibles.
     * @param string $requested Cantidad solicitada.
     */
    public function __construct(
        public readonly string $variantId,
        public readonly string $available,
        public readonly string $requested,
    ) {
        parent::__construct('Stock insuficiente para la operación.');
    }
}
