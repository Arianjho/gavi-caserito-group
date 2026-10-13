<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Límites del plan por cuenta; nulo significa sin tope.
 */
return new class extends Migration
{
    /**
     * Agrega las columnas de plan y límites a `core.tenants`.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE core.tenants ADD COLUMN plan_code varchar(30) NOT NULL DEFAULT 'beta'");
        DB::statement('ALTER TABLE core.tenants ADD COLUMN max_users integer NULL');
        DB::statement('ALTER TABLE core.tenants ADD COLUMN max_companies integer NULL');
        DB::statement('ALTER TABLE core.tenants ADD COLUMN max_documents_month integer NULL');
        DB::statement("COMMENT ON COLUMN core.tenants.plan_code IS 'Código del plan contratado'");
        DB::statement("COMMENT ON COLUMN core.tenants.max_users IS 'Tope de usuarios activos; nulo = sin tope'");
        DB::statement("COMMENT ON COLUMN core.tenants.max_companies IS 'Tope de empresas activas; nulo = sin tope'");
        DB::statement("COMMENT ON COLUMN core.tenants.max_documents_month IS 'Tope de documentos de venta por mes; nulo = sin tope'");
    }

    /**
     * Revierte las columnas.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE core.tenants DROP COLUMN IF EXISTS plan_code, DROP COLUMN IF EXISTS max_users, '
            .'DROP COLUMN IF EXISTS max_companies, DROP COLUMN IF EXISTS max_documents_month');
    }
};
