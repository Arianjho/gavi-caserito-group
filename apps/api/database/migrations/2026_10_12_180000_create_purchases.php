<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compras a proveedores: documentos con ingreso de stock, condición de pago
 * (contado o crédito) y pagos parciales que alimentan las cuentas por pagar.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `purch` y sus tablas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS purch');
        DB::statement("COMMENT ON SCHEMA purch IS 'Compras: documentos de compra, líneas y pagos a proveedores'");

        $this->createDocuments();
        $this->createLines();
        $this->createPayments();
    }

    /**
     * Crea `purch.purchase_documents`.
     */
    private function createDocuments(): void
    {
        Schema::create('purch.purchase_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa que compra');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->uuid('warehouse_id')->comment('Almacén que recibe');
            $table->foreign('warehouse_id')->references('id')->on('core.warehouses');
            $table->uuid('supplier_id')->comment('Proveedor');
            $table->foreign('supplier_id')->references('id')->on('cat.parties');
            $table->unsignedInteger('number')->comment('Correlativo interno por empresa');
            $table->string('supplier_document', 40)->nullable()->comment('Serie y número del comprobante del proveedor');
            $table->date('issued_date')->comment('Fecha del comprobante');
            $table->decimal('total', 14, 2)->comment('Importe total de la compra');
            $table->decimal('paid_total', 14, 2)->default(0)->comment('Importe pagado');
            $table->string('payment_condition', 6)->default('cash')->comment('cash o credit');
            $table->date('due_date')->nullable()->comment('Vencimiento si es a crédito');
            $table->string('status', 10)->default('confirmed')->comment('confirmed o voided');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->timestampTz('voided_at')->nullable()->comment('Fecha de anulación');
            $table->text('void_reason')->nullable()->comment('Motivo de anulación');
            $table->auditColumns();
            $table->index(['tenant_id', 'supplier_id']);
        });
        DB::statement(
            'ALTER TABLE purch.purchase_documents ADD COLUMN balance numeric(14,2) GENERATED ALWAYS AS (total - paid_total) STORED'
        );
        DB::statement("COMMENT ON COLUMN purch.purchase_documents.balance IS 'Saldo por pagar (total menos pagado)'");
        DB::statement("COMMENT ON TABLE purch.purchase_documents IS 'Documentos de compra a proveedores'");
        DB::statement(
            "ALTER TABLE purch.purchase_documents ADD CONSTRAINT purchase_documents_status_check CHECK (status IN ('confirmed','voided'))"
        );
        DB::statement(
            "ALTER TABLE purch.purchase_documents ADD CONSTRAINT purchase_documents_condition_check CHECK (payment_condition IN ('cash','credit'))"
        );
        DB::statement('CREATE UNIQUE INDEX purchase_documents_number_unique ON purch.purchase_documents (company_id, number)');
        TenantSecurity::protect('purch.purchase_documents');
    }

    /**
     * Crea `purch.purchase_lines`.
     */
    private function createLines(): void
    {
        Schema::create('purch.purchase_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('document_id')->comment('Documento de compra');
            $table->foreign('document_id')->references('id')->on('purch.purchase_documents');
            $table->unsignedSmallInteger('line_no')->comment('Número de línea');
            $table->uuid('variant_id')->comment('Variante comprada');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->decimal('qty', 14, 3)->comment('Cantidad recibida');
            $table->decimal('unit_cost', 14, 4)->comment('Costo unitario');
            $table->decimal('total', 14, 2)->comment('Total de la línea');
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE purch.purchase_lines IS 'Líneas de los documentos de compra'");
        TenantSecurity::protect('purch.purchase_lines');
    }

    /**
     * Crea `purch.purchase_payments`.
     */
    private function createPayments(): void
    {
        Schema::create('purch.purchase_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('document_id')->comment('Documento de compra');
            $table->foreign('document_id')->references('id')->on('purch.purchase_documents');
            $table->string('method', 12)->comment('Medio de pago');
            $table->decimal('amount', 14, 2)->comment('Importe pagado');
            $table->string('reference', 80)->nullable()->comment('Referencia u operación');
            $table->timestampTz('paid_at')->useCurrent()->comment('Fecha y hora del pago');
            $table->uuid('cash_session_id')->nullable()->comment('Turno de caja si salió efectivo de caja');
            $table->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            $table->appendOnlyAudit();
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE purch.purchase_payments IS 'Pagos realizados a proveedores por documento'");
        TenantSecurity::protect('purch.purchase_payments');
    }

    /**
     * Revierte el esquema de compras.
     */
    public function down(): void
    {
        Schema::dropIfExists('purch.purchase_payments');
        Schema::dropIfExists('purch.purchase_lines');
        Schema::dropIfExists('purch.purchase_documents');
        DB::statement('DROP SCHEMA IF EXISTS purch');
    }
};
