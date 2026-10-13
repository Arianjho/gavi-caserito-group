<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Venta a crédito: condición de pago, vencimiento y saldo por cobrar.
 */
return new class extends Migration
{
    /**
     * Agrega las columnas de crédito a `sales.sales_documents`.
     */
    public function up(): void
    {
        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->string('payment_condition', 6)->default('cash')->after('currency')
                ->comment('cash (contado) o credit (crédito)');
            $table->date('due_date')->nullable()->after('payment_condition')
                ->comment('Fecha de vencimiento de la deuda (crédito)');
        });

        DB::statement(
            "ALTER TABLE sales.sales_documents ADD CONSTRAINT sales_documents_condition_check CHECK (payment_condition IN ('cash','credit'))"
        );
        DB::statement(
            'ALTER TABLE sales.sales_documents ADD COLUMN balance numeric(14,2) GENERATED ALWAYS AS (total - paid_total) STORED'
        );
        DB::statement("COMMENT ON COLUMN sales.sales_documents.balance IS 'Saldo por cobrar (total menos cobrado)'");
        DB::statement(
            "CREATE INDEX sales_documents_receivable_idx ON sales.sales_documents (tenant_id, due_date) WHERE status = 'confirmed' AND total > paid_total"
        );
    }

    /**
     * Quita las columnas agregadas.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS sales.sales_documents_receivable_idx');
        DB::statement('ALTER TABLE sales.sales_documents DROP COLUMN IF EXISTS balance');
        DB::statement('ALTER TABLE sales.sales_documents DROP CONSTRAINT IF EXISTS sales_documents_condition_check');
        Schema::table('sales.sales_documents', function (Blueprint $table): void {
            $table->dropColumn(['payment_condition', 'due_date']);
        });
    }
};
