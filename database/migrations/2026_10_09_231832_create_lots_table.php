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
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('owner_supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('inventory_operation_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->decimal('quantity_received', 12, 3);
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['product_id', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
