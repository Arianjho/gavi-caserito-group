<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Perfiles (roles), sus permisos y la asignación a usuarios.
 *
 * El catálogo de permisos vive en código (`PermissionCatalog`); aquí solo se
 * guardan los códigos concedidos o denegados a cada perfil.
 */
return new class extends Migration
{
    /**
     * Crea `core.roles`, `core.role_permissions` y `core.user_roles`.
     */
    public function up(): void
    {
        $this->createRoles();
        $this->createRolePermissions();
        $this->createUserRoles();
    }

    /**
     * Crea `core.roles`.
     */
    private function createRoles(): void
    {
        Schema::create('core.roles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador del perfil');
            $table->uuid('tenant_id')->comment('Cuenta propietaria del perfil');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 80)->comment('Nombre del perfil');
            $table->string('description', 255)->nullable()->comment('Para qué sirve el perfil');
            $table->boolean('is_system')->default(false)->comment('Perfil de plantilla; no se elimina');
            $table->string('base_template', 40)->nullable()->comment('Plantilla de origen (administrator, seller, cashier...)');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE core.roles IS 'Perfiles de acceso por cuenta'");
        DB::statement(
            "CREATE UNIQUE INDEX roles_tenant_name_unique ON core.roles (tenant_id, lower(name)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('core.roles');
        TenantSecurity::protect('core.roles');
    }

    /**
     * Crea `core.role_permissions`.
     */
    private function createRolePermissions(): void
    {
        Schema::create('core.role_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('role_id')->comment('Perfil');
            $table->foreign('role_id')->references('id')->on('core.roles')->cascadeOnDelete();
            $table->string('permission_code', 80)->comment('Código del permiso (modulo.recurso.accion)');
            $table->string('effect', 8)->default('allow')->comment('allow o deny; deny prevalece');
            $table->appendOnlyAudit();
            $table->unique(['role_id', 'permission_code']);
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE core.role_permissions IS 'Permisos concedidos o denegados a un perfil'");
        DB::statement("ALTER TABLE core.role_permissions ADD CONSTRAINT role_permissions_effect_check CHECK (effect IN ('allow','deny'))");
        TenantSecurity::protect('core.role_permissions');
    }

    /**
     * Crea `core.user_roles`.
     */
    private function createUserRoles(): void
    {
        Schema::create('core.user_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->uuid('user_id')->comment('Usuario');
            $table->foreign('user_id')->references('id')->on('core.users')->cascadeOnDelete();
            $table->uuid('role_id')->comment('Perfil asignado');
            $table->foreign('role_id')->references('id')->on('core.roles');
            $table->appendOnlyAudit();
            $table->unique(['user_id', 'role_id']);
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE core.user_roles IS 'Perfiles asignados a cada usuario'");
        TenantSecurity::protect('core.user_roles');
    }

    /**
     * Revierte las tablas creadas.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.user_roles');
        Schema::dropIfExists('core.role_permissions');
        Schema::dropIfExists('core.roles');
    }
};
