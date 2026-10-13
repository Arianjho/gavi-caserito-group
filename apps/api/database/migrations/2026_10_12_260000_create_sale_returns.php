<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones parciales o totales de una venta ya cobrada: reingresan
 * stock y devuelven el dinero al cliente.
 */
return new class extends Migration
{
    /**
     * Crea `sales.sale_returns` y `sales.sale_return_lines`.
     */
    public function up(): void
    {
        $this->createReturns();
        $this->createLines();
    }

    /**
     * Crea `sales.sale_returns`.
     */
    private function createReturns(): void
    {
        Schema::create('sales.sale_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->unsignedInteger('number')->comment('Correlativo de la devolución por empresa');
            $table->uuid('sale_id')->comment('Venta devuelta');
            $table->foreign('sale_id')->references('id')->on('sales.sales_documents');
            $table->string('reason', 255)->comment('Motivo de la devolución');
            $table->string('refund_method', 12)->comment('Medio con el que se devolvió el dinero');
            $table->decimal('total', 14, 2)->comment('Importe devuelto con IGV');
            $table->decimal('net_value', 14, 2)->comment('Valor de venta devuelto (sin IGV)');
            $table->decimal('cost', 14, 2)->default(0)->comment('Costo de la mercadería reingresada');
            $table->uuid('cash_session_id')->nullable()->comment('Turno del que salió el efectivo');
            $table->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            $table->timestampTz('returned_at')->useCurrent()->comment('Fecha y hora de la devolución');
            $table->auditColumns();
            $table->index(['tenant_id', 'returned_at']);
        });
        DB::statement("COMMENT ON TABLE sales.sale_returns IS 'Devoluciones de ventas'");
        DB::statement('CREATE UNIQUE INDEX sale_returns_number_unique ON sales.sale_returns (company_id, number)');
        TenantSecurity::protect('sales.sale_returns');
    }

    /**
     * Crea `sales.sale_return_lines`.
     */
    private function createLines(): void
    {
        Schema::create('sales.sale_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('return_id')->comment('Devolución');
            $table->foreign('return_id')->references('id')->on('sales.sale_returns');
            $table->uuid('sale_line_id')->comment('Línea de la venta que se devuelve');
            $table->foreign('sale_line_id')->references('id')->on('sales.sales_document_lines');
            $table->uuid('variant_id')->comment('Variante devuelta');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->decimal('qty', 14, 3)->comment('Cantidad devuelta');
            $table->decimal('total', 14, 2)->comment('Importe devuelto con IGV');
            $table->index('return_id');
            $table->index('sale_line_id');
        });
        DB::statement("COMMENT ON TABLE sales.sale_return_lines IS 'Líneas devueltas por devolución'");
        TenantSecurity::protect('sales.sale_return_lines');
    }

    /**
     * Revierte las tablas de devoluciones.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales.sale_return_lines');
        Schema::dropIfExists('sales.sale_returns');
    }
};
