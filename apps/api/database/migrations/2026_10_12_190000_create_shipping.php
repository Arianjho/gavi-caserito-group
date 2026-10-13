<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Envíos: agencias de transporte, envíos de pedidos y línea de tiempo de estados.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `ship` y sus tablas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS ship');
        DB::statement("COMMENT ON SCHEMA ship IS 'Envíos: agencias, envíos y seguimiento'");

        $this->createCarriers();
        $this->createShipments();
        $this->createEvents();
    }

    /**
     * Crea `ship.carriers`.
     */
    private function createCarriers(): void
    {
        Schema::create('ship.carriers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 120)->comment('Nombre de la agencia o transportista');
            $table->string('kind', 10)->default('agency')->comment('agency, courier, own o pickup');
            $table->string('tracking_url_template', 255)->nullable()->comment('URL de seguimiento con {code}');
            $table->string('phone', 30)->nullable()->comment('Teléfono de contacto');
            $table->text('notes')->nullable()->comment('Observaciones');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'name']);
        });
        DB::statement("COMMENT ON TABLE ship.carriers IS 'Agencias de transporte y couriers'");
        DB::statement(
            "ALTER TABLE ship.carriers ADD CONSTRAINT carriers_kind_check CHECK (kind IN ('agency','courier','own','pickup'))"
        );
        TenantSecurity::lifecycleCheck('ship.carriers');
        TenantSecurity::protect('ship.carriers');
    }

    /**
     * Crea `ship.shipments`.
     */
    private function createShipments(): void
    {
        Schema::create('ship.shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal de despacho');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->unsignedInteger('number')->comment('Correlativo del envío por empresa');
            $table->uuid('order_id')->nullable()->comment('Pedido que origina el envío');
            $table->foreign('order_id')->references('id')->on('sales.orders');
            $table->uuid('party_id')->nullable()->comment('Cliente destinatario del pedido');
            $table->foreign('party_id')->references('id')->on('cat.parties');
            $table->uuid('carrier_id')->comment('Agencia o transportista');
            $table->foreign('carrier_id')->references('id')->on('ship.carriers');
            $table->string('delivery_mode', 15)->default('agency_pickup')->comment('agency_pickup o home_delivery');
            $table->string('destination_office', 160)->nullable()->comment('Agencia o dirección de destino');
            $table->string('recipient_name', 160)->comment('Nombre de quien recibe');
            $table->string('recipient_doc', 20)->nullable()->comment('DNI/RUC de quien recibe');
            $table->string('recipient_phone', 30)->nullable()->comment('Teléfono del destinatario');
            $table->string('carrier_order_number', 40)->nullable()->comment('Número de orden de la agencia');
            $table->string('pickup_code', 40)->nullable()->comment('Código de recojo / seguimiento');
            $this->freightColumns($table);
            $table->unsignedSmallInteger('packages_count')->default(1)->comment('Cantidad de bultos');
            $table->string('content_description', 255)->nullable()->comment('Descripción del contenido');
            $table->string('status', 22)->default('pending')->comment('Estado del envío');
            $table->timestampTz('shipped_at')->nullable()->comment('Fecha de despacho');
            $table->timestampTz('delivered_at')->nullable()->comment('Fecha de entrega');
            $table->string('public_token', 40)->unique()->comment('Token para seguimiento público');
            $table->auditColumns();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("COMMENT ON TABLE ship.shipments IS 'Envíos a clientes por agencia o reparto'");
        DB::statement(
            "ALTER TABLE ship.shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('pending','dispatched','in_transit','at_destination_office','delivered','returned','incident'))"
        );
        DB::statement('CREATE UNIQUE INDEX shipments_number_unique ON ship.shipments (company_id, number)');
        TenantSecurity::protect('ship.shipments');
    }

    /**
     * Columnas de flete y cobro en destino.
     *
     * @param Blueprint $table Tabla en definición.
     */
    private function freightColumns(Blueprint $table): void
    {
        $table->decimal('freight_amount', 12, 2)->default(0)->comment('Costo del flete');
        $table->string('freight_payer', 10)->default('sender')->comment('Quién paga: sender o recipient');
        $table->string('freight_status', 20)->default('prepaid')->comment('prepaid, pending o collect_on_delivery');
        $table->decimal('cod_amount', 12, 2)->default(0)->comment('Monto a cobrar en destino');
    }

    /**
     * Crea `ship.shipment_events`.
     */
    private function createEvents(): void
    {
        Schema::create('ship.shipment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('shipment_id')->comment('Envío');
            $table->foreign('shipment_id')->references('id')->on('ship.shipments');
            $table->string('status', 22)->comment('Estado registrado');
            $table->text('note')->nullable()->comment('Nota del evento');
            $table->timestampTz('occurred_at')->useCurrent()->comment('Fecha y hora del evento');
            $table->appendOnlyAudit();
            $table->index('shipment_id');
        });
        DB::statement("COMMENT ON TABLE ship.shipment_events IS 'Línea de tiempo de estados de un envío'");
        TenantSecurity::protect('ship.shipment_events');
    }

    /**
     * Revierte el esquema de envíos.
     */
    public function down(): void
    {
        Schema::dropIfExists('ship.shipment_events');
        Schema::dropIfExists('ship.shipments');
        Schema::dropIfExists('ship.carriers');
        DB::statement('DROP SCHEMA IF EXISTS ship');
    }
};
