<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operadores del proveedor (panel SaaS): personas que administran las cuentas.
 * Viven fuera de cualquier cuenta y no se mezclan con los usuarios de clientes.
 */
return new class extends Migration
{
    /**
     * Crea `core.operators`.
     */
    public function up(): void
    {
        Schema::create('core.operators', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->string('name', 120)->comment('Nombre del operador');
            $table->string('email', 190)->comment('Correo de acceso');
            $table->string('password', 255)->comment('Contraseña con hash');
            $table->string('status', 10)->default('active')->comment('active o inactive');
            $table->timestampTz('last_login_at')->nullable()->comment('Último ingreso');
            $table->timestampsTz();
        });
        DB::statement("COMMENT ON TABLE core.operators IS 'Operadores del proveedor que administran las cuentas (panel SaaS)'");
        DB::statement('CREATE UNIQUE INDEX operators_email_unique ON core.operators (lower(email))');
        DB::statement(
            "ALTER TABLE core.operators ADD CONSTRAINT operators_status_check CHECK (status IN ('active','inactive'))"
        );
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.operators');
    }
};
