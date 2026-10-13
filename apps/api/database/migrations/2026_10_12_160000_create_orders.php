<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos: documento de compromiso con reserva de stock, anticipos y
 * entregas parciales que generan ventas.
 */
return new class extends Migration
{
    /**
     * Crea `sales.orders`, `sales.order_lines` y `sales.order_payments`.
     */
    public function up(): void
    {
        $this->createOrders();
        $this->createLines();
        $this->createPayments();

        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->uuid('order_id')->nullable()->after('party_id')->comment('Pedido del que proviene la venta');
            $table->foreign('order_id')->references('id')->on('sales.orders');
        });
    }

    /**
     * Crea `sales.orders`.
     */
    private function createOrders(): void
    {
        Schema::create('sales.orders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->uuid('warehouse_id')->comment('Almacén que reserva y entrega');
            $table->foreign('warehouse_id')->references('id')->on('core.warehouses');
            $table->unsignedInteger('number')->comment('Correlativo del pedido por empresa');
            $table->uuid('party_id')->comment('Cliente');
            $table->foreign('party_id')->references('id')->on('cat.parties');
            $table->uuid('seller_id')->nullable()->comment('Vendedor que tomó el pedido');
            $table->string('source', 12)->default('counter')->comment('Canal: whatsapp, counter, portal, phone o import');
            $table->string('status', 12)->default('confirmed')->comment('confirmed, in_dispatch, ready, delivered o cancelled');
            $table->string('delivery_type', 10)->default('pickup')->comment('pickup (recojo), shipment (envío a agencia) o delivery');
            $table->date('requested_date')->nullable()->comment('Fecha solicitada de entrega');
            $table->decimal('total', 14, 2)->default(0)->comment('Importe total del pedido');
            $table->decimal('prepaid_total', 14, 2)->default(0)->comment('Anticipos recibidos');
            $table->decimal('prepaid_applied', 14, 2)->default(0)->comment('Anticipos aplicados a ventas');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->string('public_token', 40)->unique()->comment('Token para seguimiento público');
            $table->timestampTz('cancelled_at')->nullable()->comment('Fecha de cancelación');
            $table->text('cancel_reason')->nullable()->comment('Motivo de cancelación');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE sales.orders IS 'Pedidos de clientes con reserva de stock'");
        DB::statement(
            "ALTER TABLE sales.orders ADD CONSTRAINT orders_status_check CHECK (status IN ('confirmed','in_dispatch','ready','delivered','cancelled'))"
        );
        DB::statement('CREATE UNIQUE INDEX orders_number_unique ON sales.orders (company_id, number)');
        TenantSecurity::protect('sales.orders');
    }

    /**
     * Crea `sales.order_lines`.
     */
    private function createLines(): void
    {
        Schema::create('sales.order_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('order_id')->comment('Pedido');
            $table->foreign('order_id')->references('id')->on('sales.orders');
            $table->unsignedSmallInteger('line_no')->comment('Número de línea');
            $table->uuid('variant_id')->comment('Variante pedida');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->string('description', 255)->comment('Descripción');
            $table->decimal('qty_ordered', 14, 3)->comment('Cantidad pedida');
            $table->decimal('qty_delivered', 14, 3)->default(0)->comment('Cantidad ya entregada (vendida)');
            $table->decimal('qty_reserved', 14, 3)->default(0)->comment('Cantidad reservada vigente');
            $table->decimal('unit_price', 14, 4)->comment('Precio unitario con IGV');
            $table->decimal('discount_pct', 6, 3)->default(0)->comment('Descuento en porcentaje');
            $table->string('affectation', 2)->default('10')->comment('Afectación IGV');
            $table->decimal('total', 14, 2)->comment('Total de la línea con IGV');
            $table->index('order_id');
        });
        DB::statement("COMMENT ON TABLE sales.order_lines IS 'Líneas de pedido con cantidades por etapa'");
        TenantSecurity::protect('sales.order_lines');
    }

    /**
     * Crea `sales.order_payments` (anticipos).
     */
    private function createPayments(): void
    {
        Schema::create('sales.order_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('order_id')->comment('Pedido');
            $table->foreign('order_id')->references('id')->on('sales.orders');
            $table->string('method', 12)->comment('Medio de pago');
            $table->decimal('amount', 14, 2)->comment('Importe del anticipo');
            $table->string('reference', 80)->nullable()->comment('Referencia u operación');
            $table->timestampTz('paid_at')->useCurrent()->comment('Fecha y hora del anticipo');
            $table->appendOnlyAudit();
            $table->index('order_id');
        });
        DB::statement("COMMENT ON TABLE sales.order_payments IS 'Anticipos recibidos por pedido'");
        TenantSecurity::protect('sales.order_payments');
    }

    /**
     * Revierte las tablas de pedidos.
     */
    public function down(): void
    {
        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropColumn('order_id');
        });
        Schema::dropIfExists('sales.order_payments');
        Schema::dropIfExists('sales.order_lines');
        Schema::dropIfExists('sales.orders');
    }
};
