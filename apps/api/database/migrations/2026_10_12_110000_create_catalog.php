<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo: categorías, marcas, ítems, variantes y códigos de barras.
 *
 * Todo ítem tiene al menos una variante (la predeterminada); los pedidos,
 * ventas, stock y precios referencian siempre la variante.
 */
return new class extends Migration
{
    /**
     * Crea el esquema `cat` y sus tablas.
     */
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS cat');
        DB::statement("COMMENT ON SCHEMA cat IS 'Catálogo: productos, servicios, variantes y terceros'");

        $this->createCategories();
        $this->createBrands();
        $this->createItems();
        $this->createVariants();
        $this->createBarcodes();
    }

    /**
     * Crea `cat.categories` (árbol de categorías).
     */
    private function createCategories(): void
    {
        Schema::create('cat.categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('parent_id')->nullable()->comment('Categoría padre');
            $table->string('name', 120)->comment('Nombre de la categoría');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        Schema::table('cat.categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('cat.categories');
        });
        DB::statement("COMMENT ON TABLE cat.categories IS 'Categorías de productos (árbol)'");
        DB::statement(
            "CREATE UNIQUE INDEX categories_tenant_parent_name_unique ON cat.categories (tenant_id, COALESCE(parent_id, '00000000-0000-0000-0000-000000000000'::uuid), lower(name)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.categories');
        TenantSecurity::protect('cat.categories');
    }

    /**
     * Crea `cat.brands`.
     */
    private function createBrands(): void
    {
        Schema::create('cat.brands', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 120)->comment('Nombre de la marca');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE cat.brands IS 'Marcas de productos'");
        DB::statement(
            "CREATE UNIQUE INDEX brands_tenant_name_unique ON cat.brands (tenant_id, lower(name)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.brands');
        TenantSecurity::protect('cat.brands');
    }

    /**
     * Crea `cat.items` (producto o servicio).
     */
    private function createItems(): void
    {
        Schema::create('cat.items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('kind', 8)->default('good')->comment('good (bien) o service (servicio)');
            $table->string('code', 40)->nullable()->comment('Código interno opcional');
            $table->string('name', 200)->comment('Nombre del producto o servicio');
            $table->text('description')->nullable()->comment('Descripción');
            $table->uuid('category_id')->nullable()->comment('Categoría');
            $table->foreign('category_id')->references('id')->on('cat.categories');
            $table->uuid('brand_id')->nullable()->comment('Marca');
            $table->foreign('brand_id')->references('id')->on('cat.brands');
            $table->string('base_uom', 3)->default('NIU')->comment('Unidad de medida base (Catálogo 03 SUNAT)');
            $table->boolean('tracks_stock')->default(true)->comment('Si se controla stock; falso para servicios');
            $table->boolean('has_variants')->default(false)->comment('Si tiene más de una variante');
            $table->string('default_tax_affectation', 2)->nullable()->comment('Afectación IGV por defecto (Catálogo 07); nulo = según empresa');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'name']);
        });
        DB::statement("COMMENT ON TABLE cat.items IS 'Productos y servicios (agrupan variantes)'");
        DB::statement("ALTER TABLE cat.items ADD CONSTRAINT items_kind_check CHECK (kind IN ('good','service'))");
        DB::statement(
            "CREATE UNIQUE INDEX items_tenant_code_unique ON cat.items (tenant_id, lower(code)) WHERE code IS NOT NULL AND status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.items');
        TenantSecurity::protect('cat.items');
    }

    /**
     * Crea `cat.item_variants`.
     */
    private function createVariants(): void
    {
        Schema::create('cat.item_variants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('item_id')->comment('Ítem al que pertenece');
            $table->foreign('item_id')->references('id')->on('cat.items');
            $table->string('sku', 60)->nullable()->comment('Código SKU de la variante');
            $table->string('name', 200)->nullable()->comment('Nombre de la variante (por ejemplo "Rojo / M")');
            $table->boolean('is_default')->default(false)->comment('Variante predeterminada del ítem');
            $table->decimal('cost_reference', 14, 4)->nullable()->comment('Costo de referencia');
            $table->decimal('weight', 10, 3)->nullable()->comment('Peso en kilogramos');
            $table->lifecycle();
            $table->auditColumns();
            $table->index(['tenant_id', 'item_id']);
        });
        DB::statement("COMMENT ON TABLE cat.item_variants IS 'Variantes vendibles de un ítem; todo ítem tiene al menos una'");
        DB::statement(
            "CREATE UNIQUE INDEX item_variants_tenant_sku_unique ON cat.item_variants (tenant_id, lower(sku)) WHERE sku IS NOT NULL AND status <> 'deleted'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX item_variants_one_default ON cat.item_variants (item_id) WHERE is_default AND status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('cat.item_variants');
        TenantSecurity::protect('cat.item_variants');
    }

    /**
     * Crea `cat.item_barcodes`.
     */
    private function createBarcodes(): void
    {
        Schema::create('cat.item_barcodes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('variant_id')->comment('Variante identificada por el código');
            $table->foreign('variant_id')->references('id')->on('cat.item_variants');
            $table->string('code', 64)->comment('Código de barras o código interno');
            $table->string('kind', 10)->default('ean13')->comment('ean13, code128 o internal');
            $table->appendOnlyAudit();
            $table->unique(['tenant_id', 'code']);
            $table->index('variant_id');
        });
        DB::statement("COMMENT ON TABLE cat.item_barcodes IS 'Códigos de barras de las variantes (únicos por cuenta)'");
        DB::statement(
            "ALTER TABLE cat.item_barcodes ADD CONSTRAINT item_barcodes_kind_check CHECK (kind IN ('ean13','code128','internal'))"
        );
        TenantSecurity::protect('cat.item_barcodes');
    }

    /**
     * Revierte el esquema del catálogo.
     */
    public function down(): void
    {
        Schema::dropIfExists('cat.item_barcodes');
        Schema::dropIfExists('cat.item_variants');
        Schema::dropIfExists('cat.items');
        Schema::dropIfExists('cat.brands');
        Schema::dropIfExists('cat.categories');
        DB::statement('DROP SCHEMA IF EXISTS cat');
    }
};
