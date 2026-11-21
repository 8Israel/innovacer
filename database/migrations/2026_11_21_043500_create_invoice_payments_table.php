<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('related_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('customer_payment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('installment');
            $table->decimal('previous_balance', 12, 2);
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('outstanding_balance', 12, 2);
            $table->timestamps();

            $table->index(['related_invoice_id', 'installment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
