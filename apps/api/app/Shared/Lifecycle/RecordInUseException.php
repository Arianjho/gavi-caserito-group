<?php

declare(strict_types=1);

namespace App\Shared\Lifecycle;

use RuntimeException;

/**
 * El registro tiene movimientos o dependencias y no puede eliminarse.
 */
final class RecordInUseException extends RuntimeException
{
    /**
     * Crea la excepción.
     *
     * @param array<string, int> $blockers Dependencias que bloquean la eliminación.
     * @param bool $canDeactivate Si se ofrece desactivar como alternativa.
     */
    public function __construct(
        public readonly array $blockers,
        public readonly bool $canDeactivate,
    ) {
        parent::__construct('El registro tiene dependencias y no puede eliminarse.');
    }
}
