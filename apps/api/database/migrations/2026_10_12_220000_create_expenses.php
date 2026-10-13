<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gastos operativos del negocio (alquiler, servicios, sueldos, etc.) que
 * no pasan por una compra de mercadería.
 */
return new class extends Migration
{
    /**
     * Crea `purch.expenses`.
     */
    public function up(): void
    {
        Schema::create('purch.expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa que asume el gasto');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->string('category', 60)->comment('Rubro del gasto: alquiler, servicios, sueldos, etc.');
            $table->string('description', 255)->comment('Detalle del gasto');
            $table->uuid('supplier_id')->nullable()->comment('Proveedor al que se pagó, si aplica');
            $table->foreign('supplier_id')->references('id')->on('cat.parties');
            $table->string('method', 12)->comment('Medio de pago');
            $table->decimal('amount', 14, 2)->comment('Importe pagado');
            $table->timestampTz('paid_at')->useCurrent()->comment('Fecha y hora del pago');
            $table->uuid('cash_session_id')->nullable()->comment('Turno de caja del que salió el efectivo');
            $table->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            $table->string('status', 8)->default('active')->comment('active o void');
            $table->text('void_reason')->nullable()->comment('Motivo de anulación');
            $table->auditColumns();
            $table->index(['tenant_id', 'paid_at']);
        });
        DB::statement("COMMENT ON TABLE purch.expenses IS 'Gastos operativos pagados'");
        DB::statement("ALTER TABLE purch.expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('active','void'))");
        TenantSecurity::protect('purch.expenses');
    }

    /**
     * Revierte la tabla de gastos.
     */
    public function down(): void
    {
        Schema::dropIfExists('purch.expenses');
    }
};
