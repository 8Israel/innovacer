<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a cash movement was for, and the supplier of an expense that is not
     * a debt payment (debt payments go to supplier_payments instead).
     */
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('category')->default('other')->after('type');
            $table->foreignId('supplier_id')->nullable()->after('category')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn('category');
        });
    }
};
