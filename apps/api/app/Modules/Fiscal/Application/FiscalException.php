<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use RuntimeException;

/**
 * Error del motor fiscal. `retryable` indica si conviene reintentar el envío.
 */
final class FiscalException extends RuntimeException
{
    /**
     * Crea la excepción.
     *
     * @param string $message Descripción legible del problema.
     * @param bool $retryable Si el envío puede reintentarse más tarde.
     */
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
