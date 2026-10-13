<?php

declare(strict_types=1);

use App\Shared\Database\TenantSecurity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Llaves de idempotencia: permiten reintentar una operación sin duplicarla.
 */
return new class extends Migration
{
    /**
     * Crea `core.idempotency_keys`.
     */
    public function up(): void
    {
        Schema::create('core.idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary()->comment('Identificador');
            $table->uuid('tenant_id')->comment('Cuenta propietaria');
            $table->foreign('tenant_id')->references('id')->on('core.tenants');
            $table->string('key', 100)->comment('Valor enviado en el encabezado Idempotency-Key');
            $table->string('scope', 200)->comment('Método y ruta de la operación');
            $table->string('request_hash', 64)->comment('Huella del cuerpo de la petición');
            $table->string('status', 12)->default('processing')->comment('processing o done');
            $table->unsignedSmallInteger('response_status')->nullable()->comment('Código HTTP de la respuesta guardada');
            $table->text('response_body')->nullable()->comment('Cuerpo de la respuesta guardada');
            $table->timestampTz('created_at')->useCurrent()->comment('Creación');
            $table->unique(['tenant_id', 'scope', 'key']);
        });
        DB::statement("COMMENT ON TABLE core.idempotency_keys IS 'Resultados guardados por llave de idempotencia (se purgan a las 24 h)'");
        TenantSecurity::protect('core.idempotency_keys');
    }

    /**
     * Revierte la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('core.idempotency_keys');
    }
};
