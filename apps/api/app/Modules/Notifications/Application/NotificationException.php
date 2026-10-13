<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use RuntimeException;

/**
 * Falla al notificar: indica si conviene reintentar más tarde.
 */
final class NotificationException extends RuntimeException
{
    /**
     * Crea la excepción.
     *
     * @param string $message Descripción del problema.
     * @param bool $retryable Si un reintento posterior puede tener éxito.
     */
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
