<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de atributos reutilizables para generar variantes (Talla, Color…).
 */
return new class extends Migration
{
    /**
     * Crea `cat.attributes`.
     */
    public function up(): void
    {
        Schema::create('cat.attributes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 60)->comment('Nombre del atributo, por ejemplo Talla');
            $table->string('suggested_values', 500)->nullable()->comment('Valores sugeridos separados por coma');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE cat.attributes IS 'Atributos reutilizables para generar variantes'");
        DB::statement(
            "CREATE UNIQUE INDEX attributes_tenant_name_unique ON cat.attributes (tenant_id, lower(name)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.attributes');
        TenantSecurity::protect('cat.attributes');
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('cat.attributes');
    }
};
