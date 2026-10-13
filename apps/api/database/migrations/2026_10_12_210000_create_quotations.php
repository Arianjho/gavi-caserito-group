<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones: propuesta de precios sin compromiso de stock que puede
 * convertirse en pedido.
 */
return new class extends Migration
{
    /**
     * Crea `sales.quotations` y `sales.quotation_lines`.
     */
    public function up(): void
    {
        $this->createQuotations();
        $this->createLines();
    }

    /**
     * Crea `sales.quotations`.
     */
    private function createQuotations(): void
    {
        Schema::create('sales.quotations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->unsignedInteger('number')->comment('Correlativo de la cotización por empresa');
            $table->uuid('party_id')->comment('Cliente');
            $table->foreign('party_id')->references('id')->on('cat.parties');
            $table->uuid('seller_id')->nullable()->comment('Vendedor que la elaboró');
            $table->string('status', 10)->default('open')->comment('open, converted, rejected o expired');
            $table->date('valid_until')->nullable()->comment('Fecha hasta la que el precio es válido');
            $table->decimal('total', 14, 2)->default(0)->comment('Importe total cotizado');
            $table->text('notes')->nullable()->comment('Condiciones u observaciones');
            $table->uuid('order_id')->nullable()->comment('Pedido generado al convertirla');
            $table->foreign('order_id')->references('id')->on('sales.orders');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE sales.quotations IS 'Cotizaciones a clientes'");
        DB::statement(
            "ALTER TABLE sales.quotations ADD CONSTRAINT quotations_status_check CHECK (status IN ('open','converted','rejected','expired'))"
        );
        DB::statement('CREATE UNIQUE INDEX quotations_number_unique ON sales.quotations (company_id, number)');
        TenantSecurity::protect('sales.quotations');
    }

    /**
     * Crea `sales.quotation_lines`.
     */
    private function createLines(): void
    {
        Schema::create('sales.quotation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('quotation_id')->comment('Cotización');
            $table->foreign('quotation_id')->references('id')->on('sales.quotations');
            $table->unsignedSmallInteger('line_no')->comment('Número de línea');
            $table->uuid('variant_id')->comment('Variante cotizada');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->string('description', 255)->comment('Descripción');
            $table->decimal('qty', 14, 3)->comment('Cantidad');
            $table->decimal('unit_price', 14, 4)->comment('Precio unitario con IGV');
            $table->decimal('discount_pct', 6, 3)->default(0)->comment('Descuento en porcentaje');
            $table->decimal('total', 14, 2)->comment('Total de la línea con IGV');
            $table->index('quotation_id');
        });
        DB::statement("COMMENT ON TABLE sales.quotation_lines IS 'Líneas de cotización'");
        TenantSecurity::protect('sales.quotation_lines');
    }

    /**
     * Revierte las tablas de cotizaciones.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales.quotation_lines');
        Schema::dropIfExists('sales.quotations');
    }
};
