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
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('cash_difference', 12, 2);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('transfer_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_closings');
    }
};
