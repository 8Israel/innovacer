<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 7 (docs/cambios-bd.md): invoice quantities with three decimals, sale items
 * and payments that cannot disappear with their sale, and stock that can never be
 * negative in the database. MySQL 8.0.16+ enforces the CHECK; SQLite (tests) cannot
 * add one to an existing table, so it gets equivalent triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->change();
        });

        foreach (['sale_items', 'sale_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['sale_id']);
                $table->foreign('sale_id')->references('id')->on('sales')->restrictOnDelete();
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                DB::statement('CREATE TRIGGER stocks_quantity_not_negative_'.strtolower($event)." BEFORE {$event} ON stocks
                    FOR EACH ROW WHEN NEW.quantity < 0
                    BEGIN SELECT RAISE(ABORT, 'stocks.quantity cannot be negative'); END");
            }
        } else {
            DB::statement('ALTER TABLE stocks ADD CONSTRAINT stocks_quantity_not_negative CHECK (quantity >= 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS stocks_quantity_not_negative_insert');
            DB::statement('DROP TRIGGER IF EXISTS stocks_quantity_not_negative_update');
        } else {
            DB::statement('ALTER TABLE stocks DROP CHECK stocks_quantity_not_negative');
        }

        foreach (['sale_items', 'sale_payments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['sale_id']);
                $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
            });
        }

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('quantity', 10, 2)->change();
        });
    }
};
