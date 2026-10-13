<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca del negocio que ve el cliente final (seguimiento, mensajes).
 */
return new class extends Migration
{
    /**
     * Agrega color y lema a `core.tenants`.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE core.tenants ADD COLUMN brand_color varchar(7) NULL');
        DB::statement('ALTER TABLE core.tenants ADD COLUMN brand_tagline varchar(120) NULL');
        DB::statement("COMMENT ON COLUMN core.tenants.brand_color IS 'Color principal de la marca en formato #RRGGBB'");
        DB::statement("COMMENT ON COLUMN core.tenants.brand_tagline IS 'Lema corto que se muestra al cliente final'");
    }

    /**
     * Revierte las columnas.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE core.tenants DROP COLUMN IF EXISTS brand_color, DROP COLUMN IF EXISTS brand_tagline');
    }
};
