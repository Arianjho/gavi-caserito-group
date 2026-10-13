<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devoluciones de compra al proveedor: sacan stock y reducen lo que se debe
 * (o dejan saldo a favor si ya estaba pagado).
 */
return new class extends Migration
{
    /**
     * Crea `purch.purchase_returns` y `purch.purchase_return_lines`.
     */
    public function up(): void
    {
        Schema::create('purch.purchase_returns', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('document_id')->comment('Compra devuelta');
            $table->foreign('document_id')->references('id')->on('purch.purchase_documents');
            $table->string('reason', 255)->comment('Motivo de la devolución');
            $table->decimal('total', 14, 2)->comment('Importe devuelto, descontado del total de la compra');
            $table->timestampTz('returned_at')->useCurrent()->comment('Fecha y hora de la devolución');
            $table->auditColumns();
            $table->index('document_id');
        });
        DB::statement("COMMENT ON TABLE purch.purchase_returns IS 'Devoluciones de compras al proveedor'");
        TenantSecurity::protect('purch.purchase_returns');

        Schema::create('purch.purchase_return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('return_id')->comment('Devolución');
            $table->foreign('return_id')->references('id')->on('purch.purchase_returns');
            $table->uuid('purchase_line_id')->comment('Línea de compra devuelta');
            $table->foreign('purchase_line_id')->references('id')->on('purch.purchase_lines');
            $table->decimal('qty', 14, 3)->comment('Cantidad devuelta');
            $table->decimal('total', 14, 2)->comment('Importe devuelto');
            $table->index('return_id');
            $table->index('purchase_line_id');
        });
        DB::statement("COMMENT ON TABLE purch.purchase_return_lines IS 'Líneas devueltas al proveedor'");
        TenantSecurity::protect('purch.purchase_return_lines');
    }

    /**
     * Revierte las tablas.
     */
    public function down(): void
    {
        Schema::dropIfExists('purch.purchase_return_lines');
        Schema::dropIfExists('purch.purchase_returns');
    }
};
