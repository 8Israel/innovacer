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
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opened_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 12, 2);
            $table->text('opening_note')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('cash_difference', 12, 2)->nullable();
            $table->decimal('cash_left', 12, 2)->nullable();
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('transfer_total', 12, 2)->default(0);
            $table->decimal('credit_total', 12, 2)->default(0);
            $table->text('closing_note')->nullable();
            $table->string('status')->default('open');
            $table->unsignedTinyInteger('open_lock')->nullable()->unique();
            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_sessions');
    }
};
