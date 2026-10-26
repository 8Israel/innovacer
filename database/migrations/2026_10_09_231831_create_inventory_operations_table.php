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
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('responsible')->nullable();
            $table->string('reason')->nullable();
            $table->text('note')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('inventory_operations')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['type', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_operations');
    }
};
