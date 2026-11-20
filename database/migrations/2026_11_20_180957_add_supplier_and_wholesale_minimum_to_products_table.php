<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The product's main supplier, "wholesale from N pieces" (task 7), and an IVA
     * rate with no default so it has to be chosen when the product is created (task 7).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
            $table->decimal('wholesale_min_quantity', 12, 3)->nullable()->after('wholesale_price');
            $table->decimal('tax_rate', 5, 4)->nullable()->default(null)->change();
        });
    }

    /**
     * Products without an IVA rate get 0.16 back.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('wholesale_min_quantity');
        });

        DB::table('products')->whereNull('tax_rate')->update(['tax_rate' => 0.16]);

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 4)->nullable(false)->default(0.16)->change();
        });
    }
};
