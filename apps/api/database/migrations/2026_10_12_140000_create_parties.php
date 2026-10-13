<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terceros: clientes y proveedores (personas naturales o empresas).
 */
return new class extends Migration
{
    /**
     * Crea `cat.parties`.
     */
    public function up(): void
    {
        Schema::create('cat.parties', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('doc_type', 4)->default('NONE')->comment('Tipo de documento: DNI, RUC, CE, PAS o NONE');
            $table->string('doc_number', 20)->nullable()->comment('Número de documento');
            $table->string('name', 200)->comment('Nombre completo o razón social');
            $table->string('trade_name', 200)->nullable()->comment('Nombre comercial');
            $table->string('phone', 30)->nullable()->comment('Teléfono / WhatsApp');
            $table->string('email', 190)->nullable()->comment('Correo');
            $table->string('address', 255)->nullable()->comment('Dirección');
            $table->string('ubigeo', 6)->nullable()->comment('Código de ubigeo INEI');
            $table->boolean('is_customer')->default(true)->comment('Es cliente');
            $table->boolean('is_supplier')->default(false)->comment('Es proveedor');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'name']);
        });
        DB::statement("COMMENT ON TABLE cat.parties IS 'Terceros: clientes y proveedores'");
        DB::statement(
            "ALTER TABLE cat.parties ADD CONSTRAINT parties_doc_type_check CHECK (doc_type IN ('DNI','RUC','CE','PAS','NONE'))"
        );
        DB::statement(
            "CREATE UNIQUE INDEX parties_tenant_doc_unique ON cat.parties (tenant_id, doc_type, doc_number) WHERE doc_number IS NOT NULL AND status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.parties');
        TenantSecurity::protect('cat.parties');

        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->foreign('party_id')->references('id')->on('cat.parties');
        });
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->dropForeign(['party_id']);
        });
        Schema::dropIfExists('cat.parties');
    }
};
