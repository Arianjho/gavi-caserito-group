<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes de restablecimiento de contraseña: el token solo se guarda
 * como huella y vence a la hora.
 */
return new class extends Migration
{
    /**
     * Crea `core.password_resets`.
     */
    public function up(): void
    {
        Schema::create('core.password_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('user_id')->comment('Usuario que pidió el cambio');
            $table->foreign('user_id')->references('id')->on('core.users');
            $table->string('token_hash', 64)->comment('Huella SHA-256 del token enviado por correo');
            $table->timestampTz('expires_at')->comment('Vencimiento del enlace');
            $table->timestampTz('used_at')->nullable()->comment('Momento en que se usó');
            $table->timestampTz('created_at')->useCurrent()->comment('Momento de la solicitud');
            $table->index('user_id');
        });
        DB::statement("COMMENT ON TABLE core.password_resets IS 'Solicitudes de restablecimiento de contraseña'");
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.password_resets');
    }
};
