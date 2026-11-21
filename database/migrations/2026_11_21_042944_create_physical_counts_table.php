<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('physical_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('started_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->string('status')->default('in_progress');
            $table->foreignId('applied_by_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('inventory_operation_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('physical_counts');
    }
};
