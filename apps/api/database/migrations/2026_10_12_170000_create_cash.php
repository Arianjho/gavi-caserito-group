<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Caja: cajas por sucursal, turnos con arqueo y movimientos de caja chica.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `cash` y vincula los cobros a su turno.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS cash');
        DB::statement("COMMENT ON SCHEMA cash IS 'Caja: cajas, turnos, arqueos y movimientos'");

        $this->createRegisters();
        $this->createSessions();
        $this->createMovements();

        foreach (['sales.document_payments', 'sales.order_payments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('cash_session_id')->nullable()->comment('Turno de caja en el que se recibió el cobro');
                $blueprint->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            });
        }
    }

    /**
     * Crea `cash.cash_registers`.
     */
    private function createRegisters(): void
    {
        Schema::create('cash.cash_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('branch_id')->comment('Sucursal');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->string('name', 80)->comment('Nombre de la caja');
            $table->boolean('requires_session')->default(true)->comment('Si exige abrir turno para cobrar en efectivo');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'branch_id']);
        });
        DB::statement("COMMENT ON TABLE cash.cash_registers IS 'Cajas de cobro por sucursal'");
        TenantSecurity::lifecycleCheck('cash.cash_registers');
        TenantSecurity::protect('cash.cash_registers');
    }

    /**
     * Crea `cash.cash_sessions`.
     */
    private function createSessions(): void
    {
        Schema::create('cash.cash_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('cash_register_id')->comment('Caja');
            $table->foreign('cash_register_id')->references('id')->on('cash.cash_registers');
            $table->uuid('opened_by')->comment('Usuario que abrió el turno');
            $table->timestampTz('opened_at')->useCurrent()->comment('Apertura');
            $table->decimal('opening_amount', 14, 2)->default(0)->comment('Efectivo inicial');
            $table->uuid('closed_by')->nullable()->comment('Usuario que cerró el turno');
            $table->timestampTz('closed_at')->nullable()->comment('Cierre');
            $table->decimal('expected_amount', 14, 2)->nullable()->comment('Efectivo esperado al cierre');
            $table->decimal('counted_amount', 14, 2)->nullable()->comment('Efectivo contado al cierre');
            $table->decimal('difference', 14, 2)->nullable()->comment('Contado menos esperado');
            $table->text('close_notes')->nullable()->comment('Observaciones del cierre');
            $table->string('status', 8)->default('open')->comment('open o closed');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE cash.cash_sessions IS 'Turnos de caja con apertura y arqueo'");
        DB::statement("ALTER TABLE cash.cash_sessions ADD CONSTRAINT cash_sessions_status_check CHECK (status IN ('open','closed'))");
        DB::statement(
            "CREATE UNIQUE INDEX cash_sessions_one_open_per_register ON cash.cash_sessions (cash_register_id) WHERE status = 'open'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX cash_sessions_one_open_per_user ON cash.cash_sessions (opened_by) WHERE status = 'open'"
        );
        TenantSecurity::protect('cash.cash_sessions');
    }

    /**
     * Crea `cash.cash_movements`.
     */
    private function createMovements(): void
    {
        Schema::create('cash.cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('cash_session_id')->comment('Turno');
            $table->foreign('cash_session_id')->references('id')->on('cash.cash_sessions');
            $table->string('kind', 3)->comment('in (ingreso) o out (egreso)');
            $table->string('reason', 120)->comment('Motivo del movimiento');
            $table->decimal('amount', 14, 2)->comment('Importe');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->timestampTz('occurred_at')->useCurrent()->comment('Fecha y hora');
            $table->appendOnlyAudit();
            $table->index('cash_session_id');
        });
        DB::statement("COMMENT ON TABLE cash.cash_movements IS 'Ingresos y egresos de caja chica dentro de un turno'");
        DB::statement("ALTER TABLE cash.cash_movements ADD CONSTRAINT cash_movements_kind_check CHECK (kind IN ('in','out'))");
        DB::statement('ALTER TABLE cash.cash_movements ADD CONSTRAINT cash_movements_amount_check CHECK (amount > 0)');
        TenantSecurity::protect('cash.cash_movements');
    }

    /**
     * Revierte el esquema de caja.
     */
    public function down(): void
    {
        foreach (['sales.document_payments', 'sales.order_payments'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['cash_session_id']);
                $blueprint->dropColumn('cash_session_id');
            });
        }
        Schema::dropIfExists('cash.cash_movements');
        Schema::dropIfExists('cash.cash_sessions');
        Schema::dropIfExists('cash.cash_registers');
        DB::statement('DROP SCHEMA IF EXISTS cash');
    }
};
