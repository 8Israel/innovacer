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
        Schema::create('loan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lender_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_operation_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['lender_id', 'product_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_entries');
    }
};
