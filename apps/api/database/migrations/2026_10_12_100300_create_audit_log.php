<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de auditoría: registro inmutable de quién cambió qué y cuándo.
 */
return new class extends Migration
{
    /**
     * Crea `core.audit_log` con bloqueo de actualización y borrado.
     */
    public function up(): void
    {
        Schema::create('core.audit_log', function (Blueprint $table): void {
            $table->bigIncrements('id')->comment('Número correlativo del evento');
            $table->uuid('tenant_id')->nullable()->comment('Cuenta afectada');
            $table->uuid('actor_id')->nullable()->comment('Usuario que realizó la acción; nulo si fue el sistema');
            $table->string('action', 40)->comment('created, updated, deleted, restored u otra acción de negocio');
            $table->string('entity_type', 120)->comment('Tipo de entidad afectada');
            $table->string('entity_id', 64)->comment('Identificador de la entidad afectada');
            $table->jsonb('changes')->nullable()->comment('Valores anteriores y nuevos de los campos modificados');
            $table->string('ip_address', 45)->nullable()->comment('Dirección IP de origen');
            $table->timestampTz('created_at')->useCurrent()->comment('Fecha y hora del evento (UTC)');
            $table->index(['tenant_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
        DB::statement("COMMENT ON TABLE core.audit_log IS 'Bitácora inmutable de cambios; solo se agregan filas'");

        DB::statement(
            "CREATE OR REPLACE FUNCTION core.forbid_audit_change() RETURNS trigger
             LANGUAGE plpgsql AS
             \$\$ BEGIN RAISE EXCEPTION 'La bitácora de auditoría es de solo anexado'; END \$\$"
        );
        DB::statement(
            'CREATE TRIGGER audit_log_immutable BEFORE UPDATE OR DELETE ON core.audit_log
             FOR EACH ROW EXECUTE FUNCTION core.forbid_audit_change()'
        );

        TenantSecurity::protect('core.audit_log');
    }

    /**
     * Revierte la tabla y su función.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.audit_log');
        DB::statement('DROP FUNCTION IF EXISTS core.forbid_audit_change()');
    }
};
