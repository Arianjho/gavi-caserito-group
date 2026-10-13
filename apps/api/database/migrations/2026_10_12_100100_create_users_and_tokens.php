<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios de la plataforma y tokens de acceso (Sanctum).
 */
return new class extends Migration
{
    /**
     * Crea `core.users` y `core.personal_access_tokens`.
     */
    public function up(): void
    {
        Schema::create('core.users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador del usuario');
            $table->uuid('tenant_id')->comment('Cuenta a la que pertenece');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('name', 160)->comment('Nombre completo');
            $table->string('email', 190)->comment('Correo de acceso');
            $table->string('password')->comment('Hash de la contraseña');
            $table->boolean('is_owner')->default(false)->comment('Propietario de la cuenta');
            $table->timestampTz('last_login_at')->nullable()->comment('Último acceso');
            $table->lifecycle();
            $table->auditColumns();
            $table->index('tenant_id');
        });
        DB::statement("COMMENT ON TABLE core.users IS 'Usuarios que acceden al sistema'");
        DB::statement(
            "CREATE UNIQUE INDEX users_email_unique ON core.users (lower(email)) WHERE status <> 'deleted'"
        );
        TenantSecurity::lifecycleCheck('core.users');
        TenantSecurity::protect('core.users');

        Schema::create('core.personal_access_tokens', function (Blueprint $table): void {
            $table->id()->comment('Identificador del token');
            $table->string('tokenable_type')->comment('Tipo de entidad dueña del token');
            $table->uuid('tokenable_id')->comment('Identificador de la entidad dueña');
            $table->index(['tokenable_type', 'tokenable_id']);
            $table->text('name')->comment('Nombre del token (dispositivo)');
            $table->string('token', 64)->unique()->comment('Hash SHA-256 del token');
            $table->text('abilities')->nullable()->comment('Habilidades concedidas');
            $table->timestampTz('last_used_at')->nullable()->comment('Último uso');
            $table->timestampTz('expires_at')->nullable()->index()->comment('Vencimiento');
            $table->timestampTz('created_at')->useCurrent()->comment('Creación');
            $table->timestampTz('updated_at')->useCurrent()->comment('Actualización');
        });
        DB::statement("COMMENT ON TABLE core.personal_access_tokens IS 'Tokens de acceso personales (Sanctum)'");
    }

    /**
     * Revierte las tablas creadas.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.personal_access_tokens');
        Schema::dropIfExists('core.users');
    }
};
