<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura organizacional: empresas (RUC), sucursales y almacenes.
 */
return new class extends Migration
{
    /**
     * Crea `core.companies`, `core.branches` y `core.warehouses`.
     */
    public function up(): void
    {
        $this->createCompanies();
        $this->createBranches();
        $this->createWarehouses();
    }

    /**
     * Crea `core.companies`.
     */
    private function createCompanies(): void
    {
        Schema::create('core.companies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador de la empresa');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('legal_name', 200)->comment('Razón social');
            $table->string('trade_name', 200)->nullable()->comment('Nombre comercial');
            $table->string('tax_id', 11)->comment('RUC de la empresa');
            $table->string('address', 255)->nullable()->comment('Domicilio fiscal');
            $table->string('ubigeo', 6)->nullable()->comment('Código de ubigeo INEI');
            $table->string('phone', 30)->nullable()->comment('Teléfono');
            $table->string('email', 190)->nullable()->comment('Correo de contacto');
            $table->boolean('igv_applies')->default(true)->comment('Si la empresa aplica IGV');
            $table->string('base_currency', 3)->default('PEN')->comment('Moneda base (ISO 4217)');
            $table->string('fiscal_mode', 12)->default('none')->comment('Facturación electrónica: none, optional o required');
            $table->string('brand_color', 9)->nullable()->comment('Color de marca en hexadecimal');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE core.companies IS 'Empresas (una por RUC) de una cuenta'");
        DB::statement(
            "CREATE UNIQUE INDEX companies_tenant_tax_id_unique ON core.companies (tenant_id, tax_id) WHERE status <> 'deleted'"
        );
        DB::statement(
            "ALTER TABLE core.companies ADD CONSTRAINT companies_fiscal_mode_check CHECK (fiscal_mode IN ('none','optional','required'))"
        );
        TenantSecurity::lifecycleCheck('core.companies');
        TenantSecurity::protect('core.companies');
    }

    /**
     * Crea `core.branches`.
     */
    private function createBranches(): void
    {
        Schema::create('core.branches', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador de la sucursal');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa a la que pertenece');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->string('code', 4)->comment('Código de local de 4 caracteres (anexo SUNAT)');
            $table->string('name', 120)->comment('Nombre de la sucursal');
            $table->string('address', 255)->nullable()->comment('Dirección');
            $table->string('ubigeo', 6)->nullable()->comment('Código de ubigeo INEI');
            $table->boolean('is_main')->default(false)->comment('Sucursal principal de la empresa');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'company_id']);
        });
        DB::statement("COMMENT ON TABLE core.branches IS 'Sucursales o locales de una empresa'");
        DB::statement(
            "CREATE UNIQUE INDEX branches_company_code_unique ON core.branches (company_id, code) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('core.branches');
        TenantSecurity::protect('core.branches');
    }

    /**
     * Crea `core.warehouses`.
     */
    private function createWarehouses(): void
    {
        Schema::create('core.warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador del almacén');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('company_id')->comment('Empresa propietaria del stock');
            $table->foreign('company_id')->references('id')->on('core.companies');
            $table->uuid('branch_id')->comment('Sucursal donde está el almacén');
            $table->foreign('branch_id')->references('id')->on('core.branches');
            $table->string('name', 120)->comment('Nombre del almacén');
            $table->string('kind', 12)->default('sales')->comment('Tipo: sales (venta), storage (depósito) o transit (tránsito)');
            $table->boolean('allows_negative')->nullable()->comment('Permite stock negativo; nulo = política de la empresa');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'company_id', 'branch_id']);
        });
        DB::statement("COMMENT ON TABLE core.warehouses IS 'Almacenes de una sucursal'");
        DB::statement(
            "ALTER TABLE core.warehouses ADD CONSTRAINT warehouses_kind_check CHECK (kind IN ('sales','storage','transit'))"
        );
        TenantSecurity::lifecycleCheck('core.warehouses');
        TenantSecurity::protect('core.warehouses');
    }

    /**
     * Revierte las tablas creadas.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.warehouses');
        Schema::dropIfExists('core.branches');
        Schema::dropIfExists('core.companies');
    }
};
