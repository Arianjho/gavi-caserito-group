<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: libro de movimientos (solo anexado) y saldos por almacén.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `inv` con movimientos y saldos.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS inv');
        DB::statement("COMMENT ON SCHEMA inv IS 'Inventario: movimientos de stock y saldos'");

        $this->createMovements();
        $this->createBalances();
    }

    /**
     * Crea `inv.stock_movements` (libro de solo anexado).
     */
    private function createMovements(): void
    {
        Schema::create('inv.stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador del movimiento');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa dueña del stock');
            $table->uuid('warehouse_id')->comment('Almacén afectado');
            $table->foreign('warehouse_id')->references('id')->on('core.warehouses');
            $table->uuid('variant_id')->comment('Variante afectada');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->string('movement_type', 20)->comment('opening, adjustment_in, adjustment_out, sale, purchase, transfer_in, transfer_out...');
            $table->string('direction', 3)->comment('in (entrada) u out (salida)');
            $table->decimal('qty', 14, 3)->comment('Cantidad positiva en unidad base');
            $table->decimal('unit_cost', 14, 4)->comment('Costo unitario aplicado');
            $table->decimal('total_cost', 16, 4)->comment('Costo total del movimiento');
            $table->decimal('balance_after', 14, 3)->comment('Saldo del almacén después del movimiento');
            $table->string('source_type', 40)->nullable()->comment('Documento origen (adjustment, sale...)');
            $table->uuid('source_id')->nullable()->comment('Identificador del documento origen');
            $table->string('reason', 255)->nullable()->comment('Motivo del movimiento');
            $table->timestampTz('occurred_at')->useCurrent()->comment('Fecha y hora del hecho');
            $table->appendOnlyAudit();
            $table->index(['tenant_id', 'warehouse_id', 'variant_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });
        DB::statement("COMMENT ON TABLE inv.stock_movements IS 'Libro de movimientos de stock; nunca se edita ni se borra, se corrige con otro movimiento'");
        DB::statement("ALTER TABLE inv.stock_movements ADD CONSTRAINT stock_movements_direction_check CHECK (direction IN ('in','out'))");
        DB::statement('ALTER TABLE inv.stock_movements ADD CONSTRAINT stock_movements_qty_check CHECK (qty > 0)');
        DB::statement(
            "CREATE OR REPLACE FUNCTION inv.forbid_movement_change() RETURNS trigger
             LANGUAGE plpgsql AS
             \$\$ BEGIN RAISE EXCEPTION 'Los movimientos de stock son de solo anexado'; END \$\$"
        );
        DB::statement(
            'CREATE TRIGGER stock_movements_immutable BEFORE UPDATE OR DELETE ON inv.stock_movements
             FOR EACH ROW EXECUTE FUNCTION inv.forbid_movement_change()'
        );
        TenantSecurity::protect('inv.stock_movements');
    }

    /**
     * Crea `inv.stock_balances` (saldos por almacén y variante).
     */
    private function createBalances(): void
    {
        Schema::create('inv.stock_balances', function (Blueprint $table): void {
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('warehouse_id')->comment('Almacén');
            $table->foreign('warehouse_id')->references('id')->on('core.warehouses');
            $table->uuid('variant_id')->comment('Variante');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->decimal('on_hand', 14, 3)->default(0)->comment('Existencias físicas');
            $table->decimal('reserved', 14, 3)->default(0)->comment('Cantidad reservada por pedidos');
            $table->decimal('avg_cost', 14, 4)->default(0)->comment('Costo promedio ponderado');
            $table->timestampTz('last_movement_at')->nullable()->comment('Último movimiento');
            $table->primary(['warehouse_id', 'variant_id']);
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE inv.stock_balances IS 'Saldo actual por almacén y variante (proyección del libro de movimientos)'");
        TenantSecurity::protect('inv.stock_balances');
    }

    /**
     * Revierte el esquema de inventario.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv.stock_balances');
        Schema::dropIfExists('inv.stock_movements');
        DB::statement('DROP FUNCTION IF EXISTS inv.forbid_movement_change()');
        DB::statement('DROP SCHEMA IF EXISTS inv');
    }
};
