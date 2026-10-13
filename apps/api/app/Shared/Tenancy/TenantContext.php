<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Fija en la conexión de base de datos la cuenta activa que usan las
 * políticas de seguridad por filas (`app.tenant_id` y `app.scope`).
 *
 * El contexto vive en la sesión de base de datos, por lo que debe limpiarse
 * siempre al terminar la petición o el trabajo en cola.
 */
final class TenantContext
{
    /**
     * Activa el contexto de una cuenta concreta.
     *
     * @param string $tenantId Identificador de la cuenta.
     */
    public static function useTenant(string $tenantId): void
    {
        self::set('app.tenant_id', $tenantId);
        self::set('app.scope', '');
    }

    /**
     * Activa el contexto de proveedor (acceso a todas las cuentas).
     *
     * Solo para procesos controlados: inicio de sesión, aprovisionamiento y tareas de plataforma.
     */
    public static function useProvider(): void
    {
        self::set('app.tenant_id', '');
        self::set('app.scope', 'provider');
    }

    /**
     * Cuenta activa en la sesión de base de datos (cadena vacía si no hay).
     */
    public static function current(): string
    {
        return (string) DB::scalar("SELECT current_setting('app.tenant_id', true)");
    }

    /**
     * Quita cualquier contexto: sin cuenta ni acceso de proveedor.
     */
    public static function clear(): void
    {
        self::set('app.tenant_id', '');
        self::set('app.scope', '');
    }

    /**
     * Ejecuta una función con contexto de proveedor y lo limpia al terminar.
     *
     * @template T
     *
     * @param callable(): T $callback Operación a ejecutar.
     * @return T
     */
    public static function asProvider(callable $callback): mixed
    {
        self::useProvider();
        try {
            return $callback();
        } finally {
            self::clear();
        }
    }

    /**
     * Escribe un parámetro de sesión de base de datos.
     *
     * @param string $key Nombre del parámetro.
     * @param string $value Valor a fijar.
     */
    private static function set(string $key, string $value): void
    {
        DB::select('SELECT set_config(?, ?, false)', [$key, $value]);
    }
}
