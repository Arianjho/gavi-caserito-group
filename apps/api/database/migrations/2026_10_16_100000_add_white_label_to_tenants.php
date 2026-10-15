<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marca blanca completa: identidad, logos, contacto, pie y dominio propio.
 */
return new class extends Migration
{
    /**
     * Columnas de marca en `core.tenants` (texto, archivos y dominio).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const COLUMNS = [
        'brand_name' => ['varchar(80)', 'Nombre comercial que ve el cliente en lugar del nombre del producto'],
        'brand_email' => ['varchar(120)', 'Correo de contacto que se muestra en portal y documentos'],
        'brand_phone' => ['varchar(30)', 'Teléfono de contacto que se muestra en portal y documentos'],
        'brand_footer' => ['varchar(300)', 'Texto de pie para documentos y portal (condiciones, agradecimiento)'],
        'brand_logo_path' => ['varchar(255)', 'Ruta del logo principal (fondo claro)'],
        'brand_logo_dark_path' => ['varchar(255)', 'Ruta del logo para tema oscuro'],
        'brand_icon_path' => ['varchar(255)', 'Ruta del icono cuadrado (favicon, mensajes)'],
        'brand_assets_version' => ['integer NOT NULL DEFAULT 0', 'Versión de los archivos de marca, para renovar cachés'],
        'brand_powered_by' => ['boolean NOT NULL DEFAULT false', 'Muestra el pie «Con tecnología de Gavi Caserito»'],
        'custom_domain' => ['varchar(120)', 'Dominio propio con el que la cuenta abre el panel y el portal'],
        'custom_domain_token' => ['varchar(64)', 'Valor que el cliente publica en DNS (TXT) para probar que el dominio es suyo'],
        'custom_domain_verified_at' => ['timestamptz', 'Cuándo se verificó el dominio; nulo mientras no se verifique'],
    ];

    /**
     * Agrega las columnas y el índice único del dominio.
     */
    public function up(): void
    {
        foreach (self::COLUMNS as $name => [$type, $comment]) {
            DB::statement("ALTER TABLE core.tenants ADD COLUMN {$name} {$type}");
            DB::statement("COMMENT ON COLUMN core.tenants.{$name} IS '{$comment}'");
        }
        DB::statement(
            'CREATE UNIQUE INDEX tenants_custom_domain_unique ON core.tenants (lower(custom_domain)) WHERE custom_domain IS NOT NULL'
        );
    }

    /**
     * Revierte las columnas.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS core.tenants_custom_domain_unique');
        $drop = implode(', ', array_map(fn (string $name): string => "DROP COLUMN IF EXISTS {$name}", array_keys(self::COLUMNS)));
        DB::statement("ALTER TABLE core.tenants {$drop}");
    }
};
