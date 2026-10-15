<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica, segunda parte: series de notas y guías, baja de
 * comprobantes, notas de crédito y guías de remisión remitente.
 */
return new class extends Migration
{
    /**
     * Agrega series y baja a las tablas existentes y crea notas y guías.
     */
    public function up(): void
    {
        Schema::table('fiscal.efact_settings', function (Blueprint $table): void {
            $table->string('credit_invoice_series', 4)->default('FC01')->comment('Serie de notas de crédito de facturas');
            $table->string('credit_boleta_series', 4)->default('BC01')->comment('Serie de notas de crédito de boletas');
            $table->string('dispatch_series', 4)->default('T001')->comment('Serie de guías de remisión remitente');
        });

        Schema::table('fiscal.documents', function (Blueprint $table): void {
            $table->string('void_status', 10)->nullable()->comment('Baja: pending, accepted o rejected');
            $table->string('void_reason', 255)->nullable()->comment('Motivo de la baja');
            $table->string('void_efact_id', 40)->nullable()->comment('Comunicación de baja o resumen en eFact');
            $table->timestampTz('void_requested_at')->nullable()->comment('Solicitud de baja');
            $table->timestampTz('voided_at')->nullable()->comment('Baja aceptada por SUNAT');
            $table->text('void_error')->nullable()->comment('Último error o rechazo de la baja');
        });
        DB::statement('ALTER TABLE fiscal.documents DROP CONSTRAINT fiscal_documents_status_check');
        DB::statement(
            "ALTER TABLE fiscal.documents ADD CONSTRAINT fiscal_documents_status_check CHECK (status IN ('queued','pending','accepted','rejected','error','voided'))"
        );
        DB::statement(
            "ALTER TABLE fiscal.documents ADD CONSTRAINT fiscal_documents_void_status_check CHECK (void_status IS NULL OR void_status IN ('pending','accepted','rejected'))"
        );

        $this->createCreditNotes();
        $this->createDispatchGuides();
    }

    /**
     * Crea `fiscal.credit_notes`.
     */
    private function createCreditNotes(): void
    {
        Schema::create('fiscal.credit_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa emisora');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('document_id')->comment('Comprobante que modifica');
            $table->foreign('document_id')->references('id')->on('fiscal.documents');
            $table->uuid('sale_return_id')->nullable()->comment('Devolución de stock vinculada');
            $table->foreign('sale_return_id')->references('id')->on('sales.sale_returns');
            $table->string('reason_code', 2)->comment('Motivo del catálogo 09 SUNAT');
            $table->string('reason_text', 250)->comment('Descripción del motivo');
            $table->jsonb('lines')->comment('Ítems revertidos: línea de venta y cantidad, o monto');
            $table->decimal('total', 14, 2)->comment('Importe de la nota (con IGV)');
            $table->string('series', 4)->comment('Serie enviada');
            $table->string('number', 12)->nullable()->comment('Correlativo asignado por eFact');
            $table->string('external_id', 100)->unique()->comment('Identificador idempotente enviado a eFact');
            $table->string('efact_id', 40)->nullable()->comment('Identificador en eFact');
            $table->string('status', 10)->default('queued')->comment('queued, pending, accepted, rejected o error');
            $table->string('sunat_code', 20)->nullable()->comment('Código de respuesta SUNAT');
            $table->text('sunat_description')->nullable()->comment('Descripción de SUNAT');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Intentos de envío');
            $table->text('last_error')->nullable()->comment('Último error técnico o de validación');
            $table->timestampTz('sent_at')->nullable()->comment('Primer envío exitoso a eFact');
            $table->timestampTz('finished_at')->nullable()->comment('Estado final');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE fiscal.credit_notes IS 'Notas de crédito electrónicas sobre comprobantes emitidos'");
        DB::statement(
            "ALTER TABLE fiscal.credit_notes ADD CONSTRAINT fiscal_credit_notes_status_check CHECK (status IN ('queued','pending','accepted','rejected','error'))"
        );
        TenantSecurity::protect('fiscal.credit_notes');
    }

    /**
     * Crea `fiscal.dispatch_guides`.
     */
    private function createDispatchGuides(): void
    {
        Schema::create('fiscal.dispatch_guides', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa remitente');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('sales_document_id')->nullable()->comment('Venta que origina el traslado');
            $table->foreign('sales_document_id')->references('id')->on('sales.sales_documents');
            $table->uuid('shipment_id')->nullable()->comment('Envío vinculado');
            $table->foreign('shipment_id')->references('id')->on('ship.shipments');
            $this->guideDetail($table);
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE fiscal.dispatch_guides IS 'Guías de remisión electrónicas del remitente'");
        DB::statement(
            "ALTER TABLE fiscal.dispatch_guides ADD CONSTRAINT fiscal_dispatch_guides_status_check CHECK (status IN ('queued','pending','accepted','rejected','error'))"
        );
        TenantSecurity::protect('fiscal.dispatch_guides');
    }

    /**
     * Columnas de traslado, documento y estado de `fiscal.dispatch_guides`.
     *
     * @param Blueprint $table Tabla en construcción.
     */
    private function guideDetail(Blueprint $table): void
    {
        $table->string('transfer_reason', 2)->default('01')->comment('Motivo de traslado, catálogo 20 SUNAT');
        $table->string('transport_mode', 2)->comment('01 transporte público, 02 privado');
        $table->date('transfer_date')->comment('Fecha de inicio del traslado');
        $table->decimal('weight', 12, 3)->comment('Peso bruto total');
        $table->string('weight_unit', 3)->default('KGM')->comment('KGM o TNE');
        $table->unsignedInteger('packages')->nullable()->comment('Número de bultos');
        $table->jsonb('recipient')->comment('Destinatario: tipo y número de documento, nombre');
        $table->string('departure_ubigeo', 6)->comment('Ubigeo del punto de partida');
        $table->string('departure_address', 255)->comment('Dirección de partida');
        $table->string('arrival_ubigeo', 6)->comment('Ubigeo del punto de llegada');
        $table->string('arrival_address', 255)->comment('Dirección de llegada');
        $table->jsonb('transport')->comment('Transportista (público) o vehículo y conductor (privado)');
        $table->jsonb('items')->comment('Bienes trasladados');
        $table->string('series', 4)->comment('Serie enviada');
        $table->string('number', 12)->nullable()->comment('Correlativo asignado por eFact');
        $table->string('external_id', 100)->unique()->comment('Identificador idempotente enviado a eFact');
        $table->string('efact_id', 40)->nullable()->comment('Identificador en eFact');
        $table->string('status', 10)->default('queued')->comment('queued, pending, accepted, rejected o error');
        $table->string('sunat_code', 20)->nullable()->comment('Código de respuesta SUNAT');
        $table->text('sunat_description')->nullable()->comment('Descripción de SUNAT');
        $table->unsignedSmallInteger('attempts')->default(0)->comment('Intentos de envío');
        $table->text('last_error')->nullable()->comment('Último error técnico o de validación');
        $table->timestampTz('sent_at')->nullable()->comment('Primer envío exitoso a eFact');
        $table->timestampTz('finished_at')->nullable()->comment('Estado final');
    }

    /**
     * Revierte los cambios.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal.dispatch_guides');
        Schema::dropIfExists('fiscal.credit_notes');
        DB::statement('ALTER TABLE fiscal.documents DROP CONSTRAINT IF EXISTS fiscal_documents_void_status_check');
        DB::statement('ALTER TABLE fiscal.documents DROP CONSTRAINT fiscal_documents_status_check');
        DB::statement(
            "ALTER TABLE fiscal.documents ADD CONSTRAINT fiscal_documents_status_check CHECK (status IN ('queued','pending','accepted','rejected','error'))"
        );
        Schema::table('fiscal.documents', function (Blueprint $table): void {
            $table->dropColumn(['void_status', 'void_reason', 'void_efact_id', 'void_requested_at', 'voided_at', 'void_error']);
        });
        Schema::table('fiscal.efact_settings', function (Blueprint $table): void {
            $table->dropColumn(['credit_invoice_series', 'credit_boleta_series', 'dispatch_series']);
        });
    }
};
