<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ventas: series de numeración, documentos de venta, líneas y pagos.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `sales` con sus tablas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS sales');
        DB::statement("COMMENT ON SCHEMA sales IS 'Ventas: documentos, líneas, series y cobros'");

        $this->createSeries();
        $this->createDocuments();
        $this->createLines();
        $this->createPayments();
    }

    /**
     * Crea `sales.document_series`.
     */
    private function createSeries(): void
    {
        Schema::create('sales.document_series', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->string('doc_type', 12)->comment('Tipo de documento: ticket (nota de venta) y, más adelante, invoice y receipt');
            $table->string('series', 4)->comment('Serie de 4 caracteres (por ejemplo N001)');
            $table->unsignedInteger('next_number')->default(1)->comment('Siguiente correlativo a emitir');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE sales.document_series IS 'Series y correlativos por empresa, sucursal y tipo de documento'");
        DB::statement(
            "CREATE UNIQUE INDEX document_series_unique ON sales.document_series (company_id, doc_type, series) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('sales.document_series');
        TenantSecurity::protect('sales.document_series');
    }

    /**
     * Crea `sales.sales_documents`.
     */
    private function createDocuments(): void
    {
        Schema::create('sales.sales_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa vendedora');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal de la venta');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->uuid('warehouse_id')->comment('Almacén del que sale el stock');
            $table->foreign('warehouse_id')->references('id')->on('core.warehouses');
            $table->uuid('series_id')->comment('Serie usada');
            $table->foreign('series_id')->references('id')->on('sales.document_series');
            $table->string('doc_type', 12)->comment('Tipo de documento');
            $table->string('series', 4)->comment('Serie emitida');
            $table->unsignedInteger('number')->comment('Correlativo emitido');
            $table->uuid('party_id')->nullable()->comment('Cliente (opcional); referencia a terceros');
            $table->string('status', 12)->default('confirmed')->comment('confirmed o voided');
            $table->string('currency', 3)->default('PEN')->comment('Moneda');
            $table->decimal('taxable_total', 14, 2)->default(0)->comment('Total operaciones gravadas');
            $table->decimal('exempt_total', 14, 2)->default(0)->comment('Total operaciones exoneradas');
            $table->decimal('unaffected_total', 14, 2)->default(0)->comment('Total operaciones inafectas');
            $table->decimal('igv_total', 14, 2)->default(0)->comment('Total IGV');
            $table->decimal('total', 14, 2)->default(0)->comment('Importe total');
            $table->decimal('paid_total', 14, 2)->default(0)->comment('Importe cobrado');
            $table->timestampTz('issued_at')->useCurrent()->comment('Fecha y hora de emisión');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->timestampTz('voided_at')->nullable()->comment('Fecha y hora de anulación');
            $table->uuid('voided_by')->nullable()->comment('Usuario que anuló');
            $table->text('void_reason')->nullable()->comment('Motivo de anulación');
            $table->auditColumns();
            $table->index(['tenant_id', 'issued_at']);
        });
        DB::statement("COMMENT ON TABLE sales.sales_documents IS 'Documentos de venta; no se eliminan, se anulan'");
        DB::statement("ALTER TABLE sales.sales_documents ADD CONSTRAINT sales_documents_status_check CHECK (status IN ('confirmed','voided'))");
        DB::statement(
            'CREATE UNIQUE INDEX sales_documents_number_unique ON sales.sales_documents (company_id, doc_type, series, number)'
        );
        TenantSecurity::protect('sales.sales_documents');
    }

    /**
     * Crea `sales.sales_document_lines`.
     */
    private function createLines(): void
    {
        Schema::create('sales.sales_document_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('document_id')->comment('Documento al que pertenece');
            $table->foreign('document_id')->references('id')->on('sales.sales_documents');
            $table->unsignedSmallInteger('line_no')->comment('Número de línea');
            $table->uuid('variant_id')->comment('Variante vendida');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->string('description', 255)->comment('Descripción en el documento');
            $table->decimal('qty', 14, 3)->comment('Cantidad');
            $table->decimal('unit_price', 14, 4)->comment('Precio unitario con IGV');
            $table->decimal('discount_pct', 6, 3)->default(0)->comment('Descuento en porcentaje');
            $table->string('affectation', 2)->default('10')->comment('Afectación IGV (Catálogo 07)');
            $table->decimal('unit_value', 16, 10)->comment('Valor unitario sin IGV');
            $table->decimal('sale_value', 14, 2)->comment('Valor de venta de la línea');
            $table->decimal('igv', 14, 2)->comment('IGV de la línea');
            $table->decimal('total', 14, 2)->comment('Total de la línea con IGV');
            $table->decimal('unit_cost', 14, 4)->nullable()->comment('Costo unitario aplicado al vender');
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE sales.sales_document_lines IS 'Líneas de los documentos de venta'");
        TenantSecurity::protect('sales.sales_document_lines');
    }

    /**
     * Crea `sales.document_payments`.
     */
    private function createPayments(): void
    {
        Schema::create('sales.document_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('document_id')->comment('Documento cobrado');
            $table->foreign('document_id')->references('id')->on('sales.sales_documents');
            $table->string('method', 12)->comment('cash, card, yape, plin, transfer u other');
            $table->decimal('amount', 14, 2)->comment('Importe cobrado');
            $table->string('reference', 80)->nullable()->comment('Referencia o número de operación');
            $table->timestampTz('paid_at')->useCurrent()->comment('Fecha y hora del cobro');
            $table->appendOnlyAudit();
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE sales.document_payments IS 'Cobros aplicados a documentos de venta (versión inicial)'");
        TenantSecurity::protect('sales.document_payments');
    }

    /**
     * Revierte el esquema de ventas.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales.document_payments');
        Schema::dropIfExists('sales.sales_document_lines');
        Schema::dropIfExists('sales.sales_documents');
        Schema::dropIfExists('sales.document_series');
        DB::statement('DROP SCHEMA IF EXISTS sales');
    }
};
