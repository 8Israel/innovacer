<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('name');
            $table->string('presentation')->nullable()->after('brand');
            $table->foreignId('category_id')->nullable()->after('presentation')->constrained()->nullOnDelete();
            $table->string('unit')->default('pieza')->after('category_id');
            $table->decimal('reference_cost', 12, 2)->nullable()->after('barcode');
            $table->decimal('wholesale_price', 12, 2)->nullable()->after('unit_price');
            $table->decimal('ieps_rate', 5, 4)->nullable()->after('tax_rate');
            $table->boolean('is_active')->default(true)->after('unit_name');

            $table->string('product_key', 8)->nullable()->change();
            $table->string('unit_key', 3)->nullable()->change();
            $table->string('unit_name')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['brand', 'presentation', 'unit', 'reference_cost', 'wholesale_price', 'ieps_rate', 'is_active']);

            $table->string('product_key', 8)->nullable(false)->change();
            $table->string('unit_key', 3)->nullable(false)->change();
            $table->string('unit_name')->nullable(false)->change();
        });
    }
};
