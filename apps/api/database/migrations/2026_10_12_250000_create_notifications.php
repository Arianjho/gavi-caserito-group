<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones: cuenta de WhatsApp (Evolution API) del negocio y bitácora
 * de mensajes enviados, además del consentimiento del cliente.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `notif`, sus tablas y `cat.parties.whatsapp_opt_in`.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS notif');
        DB::statement("COMMENT ON SCHEMA notif IS 'Notificaciones: canal de WhatsApp y mensajes enviados'");

        $this->createAccounts();
        $this->createMessages();

        Schema::table('cat.parties', function (Blueprint $table): void {
            $table->boolean('whatsapp_opt_in')->default(false)
                ->comment('El cliente aceptó recibir mensajes de WhatsApp del negocio');
        });
    }

    /**
     * Crea `notif.channel_accounts`.
     */
    private function createAccounts(): void
    {
        Schema::create('notif.channel_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('provider', 12)->default('evolution')->comment('Proveedor del canal');
            $table->string('base_url', 190)->comment('URL base de la instancia de Evolution API');
            $table->string('instance', 80)->comment('Nombre de la instancia de WhatsApp');
            $table->text('api_key')->comment('Clave de API cifrada');
            $table->boolean('enabled')->default(true)->comment('Si el canal envía mensajes');
            $table->timestampsTz();
            $table->unique('tenant_id');
        });
        DB::statement("COMMENT ON TABLE notif.channel_accounts IS 'Cuenta de WhatsApp del negocio (una por cuenta)'");
        TenantSecurity::protect('notif.channel_accounts');
    }

    /**
     * Crea `notif.messages`.
     */
    private function createMessages(): void
    {
        Schema::create('notif.messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('event', 30)->comment('sale_receipt, order_status, debt_reminder o test');
            $table->uuid('party_id')->nullable()->comment('Cliente destinatario');
            $table->foreign('party_id')->references('id')->on('cat.parties');
            $table->string('phone', 20)->comment('Teléfono en formato internacional sin +');
            $table->text('body')->comment('Texto enviado');
            $table->string('status', 8)->default('queued')->comment('queued, sent o failed');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Intentos de envío');
            $table->text('error')->nullable()->comment('Último error del proveedor');
            $table->string('provider_message_id', 120)->nullable()->comment('Identificador del mensaje en el proveedor');
            $table->string('ref_type', 20)->nullable()->comment('Documento de origen: sale, order o receivable');
            $table->uuid('ref_id')->nullable()->comment('Identificador del documento de origen');
            $table->timestampTz('queued_at')->useCurrent()->comment('Fecha de encolado');
            $table->timestampTz('sent_at')->nullable()->comment('Fecha de envío');
            $table->uuid('created_by')->nullable()->comment('Usuario que lo solicitó');
            $table->index(['tenant_id', 'queued_at']);
        });
        DB::statement("COMMENT ON TABLE notif.messages IS 'Bitácora de mensajes de WhatsApp enviados'");
        DB::statement("ALTER TABLE notif.messages ADD CONSTRAINT messages_status_check CHECK (status IN ('queued','sent','failed'))");
        TenantSecurity::protect('notif.messages');
    }

    /**
     * Revierte las tablas de notificaciones.
     */
    public function down(): void
    {
        Schema::table('cat.parties', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_opt_in');
        });
        Schema::dropIfExists('notif.messages');
        Schema::dropIfExists('notif.channel_accounts');
        DB::statement('DROP SCHEMA IF EXISTS notif');
    }
};
