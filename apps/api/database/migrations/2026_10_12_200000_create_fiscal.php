<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturación electrónica: configuración de Gavi eFact por empresa,
 * comprobantes fiscales con su estado SUNAT y eventos de webhook recibidos.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `fiscal` y sus tablas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS fiscal');
        DB::statement("COMMENT ON SCHEMA fiscal IS 'Facturación electrónica: configuración, comprobantes y webhooks'");

        $this->createSettings();
        $this->createDocuments();
        $this->createWebhookEvents();
    }

    /**
     * Crea `fiscal.efact_settings`.
     */
    private function createSettings(): void
    {
        Schema::create('fiscal.efact_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->unique()->comment('Empresa (una configuración por RUC)');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->string('base_url', 190)->comment('URL base de la API de Gavi eFact');
            $table->string('environment', 10)->default('beta')->comment('beta o production');
            $table->text('api_key')->comment('Clave de API (cifrada)');
            $table->text('api_secret')->comment('Secreto de API (cifrado)');
            $table->text('webhook_secret')->nullable()->comment('Secreto del webhook (cifrado)');
            $table->string('boleta_series', 4)->default('B001')->comment('Serie de boletas');
            $table->string('invoice_series', 4)->default('F001')->comment('Serie de facturas');
            $table->boolean('enabled')->default(true)->comment('Si la emisión está activa');
            $table->auditColumns();
        });
        DB::statement("COMMENT ON TABLE fiscal.efact_settings IS 'Conexión con Gavi eFact por empresa'");
        DB::statement(
            "ALTER TABLE fiscal.efact_settings ADD CONSTRAINT efact_settings_env_check CHECK (environment IN ('beta','production'))"
        );
        TenantSecurity::protect('fiscal.efact_settings');
    }

    /**
     * Crea `fiscal.documents`.
     */
    private function createDocuments(): void
    {
        Schema::create('fiscal.documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa emisora');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('sales_document_id')->unique()->comment('Venta que origina el comprobante');
            $table->foreign('sales_document_id')->references('id')->on('sales.sales_documents');
            $table->string('cpe_type', 2)->comment('Tipo SUNAT: 01 factura o 03 boleta');
            $table->string('series', 4)->comment('Serie enviada');
            $table->string('number', 12)->nullable()->comment('Correlativo asignado por eFact');
            $table->string('external_id', 100)->unique()->comment('Identificador idempotente enviado a eFact');
            $table->string('efact_id', 40)->nullable()->comment('Identificador del comprobante en eFact');
            $table->string('status', 10)->default('queued')->comment('queued, pending, accepted, rejected o error');
            $table->string('sunat_code', 20)->nullable()->comment('Código de respuesta SUNAT');
            $table->text('sunat_description')->nullable()->comment('Descripción de SUNAT');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Intentos de envío');
            $table->text('last_error')->nullable()->comment('Último error técnico o de validación');
            $table->timestampTz('sent_at')->nullable()->comment('Primer envío exitoso a eFact');
            $table->timestampTz('finished_at')->nullable()->comment('Estado final (aceptado o rechazado)');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE fiscal.documents IS 'Comprobantes electrónicos emitidos a partir de ventas'");
        DB::statement(
            "ALTER TABLE fiscal.documents ADD CONSTRAINT fiscal_documents_status_check CHECK (status IN ('queued','pending','accepted','rejected','error'))"
        );
        TenantSecurity::protect('fiscal.documents');
    }

    /**
     * Crea `fiscal.webhook_events`.
     */
    private function createWebhookEvents(): void
    {
        Schema::create('fiscal.webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->string('event_id', 64)->unique()->comment('X-Gavi-Event-Id para deduplicar');
            $table->string('event', 60)->comment('Tipo de evento');
            $table->jsonb('payload')->comment('Cuerpo recibido');
            $table->timestampTz('received_at')->useCurrent()->comment('Recepción');
        });
        DB::statement("COMMENT ON TABLE fiscal.webhook_events IS 'Eventos de webhook de eFact ya procesados'");
        TenantSecurity::protect('fiscal.webhook_events');
    }

    /**
     * Revierte el esquema fiscal.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal.webhook_events');
        Schema::dropIfExists('fiscal.documents');
        Schema::dropIfExists('fiscal.efact_settings');
        DB::statement('DROP SCHEMA IF EXISTS fiscal');
    }
};
