<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atributos de la variante (talla, color, presentación...) como pares
 * nombre-valor, para filtrar y para generar combinaciones.
 */
return new class extends Migration
{
    /**
     * Agrega `attributes` a `cat.item_variants`.
     */
    public function up(): void
    {
        Schema::table('cat.item_variants', function (Blueprint $table): void {
            $table->jsonb('attributes')->nullable()->after('name')
                ->comment('Atributos de la variante: {"Talla":"M","Color":"Rojo"}');
        });
    }

    /**
     * Quita la columna.
     */
    public function down(): void
    {
        Schema::table('cat.item_variants', function (Blueprint $table): void {
            $table->dropColumn('attributes');
        });
    }
};
