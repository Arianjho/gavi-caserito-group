<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use RuntimeException;

/**
 * Error de negocio al registrar o anular una venta.
 */
final class SaleException extends RuntimeException
{
    /**
     * Crea la excepción.
     *
     * @param string $errorCode Código estable de error (inglés).
     * @param string $userMessage Mensaje para el usuario (español).
     * @param int $httpStatus Estado HTTP de la respuesta.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $userMessage,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($userMessage);
    }
}
