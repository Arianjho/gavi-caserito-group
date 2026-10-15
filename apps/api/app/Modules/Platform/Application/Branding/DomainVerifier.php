<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Branding;

/**
 * Comprueba por DNS que el cliente controla el dominio que quiere usar.
 *
 * Se separa en una clase para poder reemplazarla en pruebas sin red.
 */
class DomainVerifier
{
    /** Prefijo del registro TXT que debe publicar el cliente. */
    public const RECORD_PREFIX = '_caserito';

    /**
     * Indica si el dominio publica el valor esperado en `_caserito.{dominio}`.
     *
     * @param string $domain Dominio propio.
     * @param string $token Valor esperado.
     */
    public function hasToken(string $domain, string $token): bool
    {
        $records = @dns_get_record(self::RECORD_PREFIX.'.'.$domain, DNS_TXT);
        if ($records === false) {
            return false;
        }
        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $token) {
                return true;
            }
        }

        return false;
    }
}
