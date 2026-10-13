<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Listas de precios (mayorista, distribuidor...) con escalas por cantidad,
 * y su asignación opcional a un cliente.
 */
return new class extends Migration
{
    /**
     * Crea `cat.price_lists`, `cat.price_list_items` y `cat.parties.price_list_id`.
     */
    public function up(): void
    {
        Schema::create('cat.price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 120)->comment('Nombre de la lista (por ejemplo Mayorista)');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE cat.price_lists IS 'Listas de precios alternativas al precio base'");
        DB::statement(
            "CREATE UNIQUE INDEX price_lists_tenant_name_unique ON cat.price_lists (tenant_id, lower(name)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.price_lists');
        TenantSecurity::protect('cat.price_lists');

        Schema::create('cat.price_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('price_list_id')->comment('Lista de precios');
            $table->foreign('price_list_id')->references('id')->on('cat.price_lists');
            $table->uuid('variant_id')->comment('Variante con precio en la lista');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->decimal('min_qty', 14, 3)->default(1)->comment('Cantidad mínima desde la que aplica el precio');
            $table->decimal('price', 14, 4)->comment('Precio unitario con IGV');
            $table->unique(['price_list_id', 'variant_id', 'min_qty']);
            $table->index('variant_id');
        });
        DB::statement("COMMENT ON TABLE cat.price_list_items IS 'Precios por variante y escala de cantidad dentro de una lista'");
        TenantSecurity::protect('cat.price_list_items');

        Schema::table('cat.parties', function (Blueprint $table): void {
            $table->uuid('price_list_id')->nullable()->comment('Lista de precios que se aplica al cliente');
            $table->foreign('price_list_id')->references('id')->on('cat.price_lists');
        });
    }

    /**
     * Revierte las tablas de listas de precios.
     */
    public function down(): void
    {
        Schema::table('cat.parties', function (Blueprint $table): void {
            $table->dropForeign(['price_list_id']);
            $table->dropColumn('price_list_id');
        });
        Schema::dropIfExists('cat.price_list_items');
        Schema::dropIfExists('cat.price_lists');
    }
};
