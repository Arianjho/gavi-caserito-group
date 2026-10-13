<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema `core`: funciones de contexto de cuenta (RLS) y tabla de cuentas.
 *
 * El contexto se fija por transacción con `set_config('app.tenant_id', ...)`
 * y `set_config('app.scope', 'provider', ...)` para procesos del proveedor.
 */
return new class extends Migration
{
    /**
     * Crea el esquema, las funciones de contexto y la tabla de cuentas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS core');
        DB::statement("COMMENT ON SCHEMA core IS 'Plataforma: cuentas, usuarios, permisos y auditoría'");

        DB::statement(
            "CREATE OR REPLACE FUNCTION core.current_tenant_id() RETURNS uuid
             LANGUAGE sql STABLE AS
             \$\$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid \$\$"
        );
        DB::statement(
            "CREATE OR REPLACE FUNCTION core.is_provider() RETURNS boolean
             LANGUAGE sql STABLE AS
             \$\$ SELECT COALESCE(current_setting('app.scope', true), '') = 'provider' \$\$"
        );
        DB::statement("COMMENT ON FUNCTION core.current_tenant_id() IS 'Cuenta activa de la sesión (app.tenant_id); nulo si no hay contexto'");
        DB::statement("COMMENT ON FUNCTION core.is_provider() IS 'Verdadero en procesos del proveedor que pueden ver todas las cuentas'");

        Schema::create('core.tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador de la cuenta');
            $table->string('name', 160)->comment('Nombre comercial de la cuenta');
            $table->string('plan_status', 16)->default('trial')->comment('Estado comercial: trial, active, suspended o closed');
            $table->timestampTz('trial_ends_at')->nullable()->comment('Fin del periodo de prueba');
            $table->string('locale', 10)->default('es-PE')->comment('Idioma de la interfaz');
            $table->string('timezone', 40)->default('America/Lima')->comment('Zona horaria de negocio');
            $table->lifecycle();
            $table->auditColumns();
        });
        DB::statement("COMMENT ON TABLE core.tenants IS 'Cuenta del cliente (propietario de los datos)'");
        DB::statement(
            "ALTER TABLE core.tenants ADD CONSTRAINT tenants_plan_status_check
             CHECK (plan_status IN ('trial','active','suspended','closed'))"
        );
        TenantSecurity::lifecycleCheck('core.tenants');
        TenantSecurity::protectTenantsTable('core.tenants');
    }

    /**
     * Revierte los objetos creados.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.tenants');
        DB::statement('DROP FUNCTION IF EXISTS core.is_provider()');
        DB::statement('DROP FUNCTION IF EXISTS core.current_tenant_id()');
        DB::statement('DROP SCHEMA IF EXISTS core');
    }
};
