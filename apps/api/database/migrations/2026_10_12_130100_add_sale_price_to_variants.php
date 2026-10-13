<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precio de venta base en la variante, usado por el POS mientras no existan
 * listas de precios. Más adelante lo reemplaza la resolución por lista.
 */
return new class extends Migration
{
    /**
     * Agrega `sale_price` a `cat.item_variants`.
     */
    public function up(): void
    {
        Schema::table('cat.item_variants', function (Blueprint $table): void {
            $table->decimal('sale_price', 14, 4)->nullable()->after('cost_reference')
                ->comment('Precio de venta base con IGV (hasta tener listas de precios)');
        });
    }

    /**
     * Quita la columna.
     */
    public function down(): void
    {
        Schema::table('cat.item_variants', function (Blueprint $table): void {
            $table->dropColumn('sale_price');
        });
    }
};
