<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * Utilidades de migración para aislar datos por cuenta (tenant) con
 * seguridad por filas (Row Level Security) de PostgreSQL.
 *
 * Cada tabla de negocio debe llamar a {@see self::protect()} al crearse:
 * así la base de datos garantiza el aislamiento aunque el código de la
 * aplicación olvide filtrar por `tenant_id`.
 */
final class TenantSecurity
{
    /**
     * Restricciones de estado permitidas para los maestros con ciclo de vida.
     */
    private const LIFECYCLE_STATES = "'active','inactive','deleted'";

    /**
     * Activa el aislamiento por cuenta en una tabla con columna `tenant_id`.
     *
     * Crea una política que solo muestra y permite escribir filas de la cuenta
     * actual (`app.tenant_id`) o, en procesos del proveedor, de cualquier cuenta
     * (`app.scope = provider`). El aislamiento se fuerza incluso para el
     * propietario de la tabla.
     *
     * @param string $table Nombre calificado de la tabla (por ejemplo `core.companies`).
     */
    public static function protect(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$table}
             USING (tenant_id = core.current_tenant_id() OR core.is_provider())
             WITH CHECK (tenant_id = core.current_tenant_id() OR core.is_provider())"
        );
    }

    /**
     * Activa el aislamiento en la tabla de cuentas, donde la clave es `id`.
     *
     * @param string $table Nombre calificado de la tabla de cuentas.
     */
    public static function protectTenantsTable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(
            "CREATE POLICY tenant_isolation ON {$table}
             USING (id = core.current_tenant_id() OR core.is_provider())
             WITH CHECK (id = core.current_tenant_id() OR core.is_provider())"
        );
    }

    /**
     * Agrega la restricción que limita `status` a `active`, `inactive` o `deleted`.
     *
     * @param string $table Nombre calificado de la tabla.
     */
    public static function lifecycleCheck(string $table): void
    {
        $name = str_replace('.', '_', $table).'_status_check';
        DB::statement(
            "ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK (status IN (".self::LIFECYCLE_STATES.'))'
        );
    }
}
