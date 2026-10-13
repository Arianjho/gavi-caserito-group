<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Devolución de anticipos de pedidos cancelados.
 */
return new class extends Migration
{
    /**
     * Crea `sales.order_refunds`.
     */
    public function up(): void
    {
        Schema::create('sales.order_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('order_id')->comment('Pedido cancelado');
            $table->foreign('order_id')->references('id')->on('sales.orders');
            $table->string('method', 12)->comment('Medio con el que se devolvió el anticipo');
            $table->decimal('amount', 14, 2)->comment('Importe devuelto');
            $table->string('reason', 255)->nullable()->comment('Motivo u observación');
            $table->uuid('cash_session_id')->nullable()->comment('Turno del que salió el efectivo');
            $table->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            $table->timestampTz('refunded_at')->useCurrent()->comment('Fecha y hora de la devolución');
            $table->appendOnlyAudit();
            $table->index('order_id');
        });
        DB::statement("COMMENT ON TABLE sales.order_refunds IS 'Anticipos devueltos al cliente por pedidos cancelados'");
        TenantSecurity::protect('sales.order_refunds');
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales.order_refunds');
    }
};
