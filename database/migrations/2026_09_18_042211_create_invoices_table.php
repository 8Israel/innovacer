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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('draft');
            $table->string('facturapi_id')->nullable()->unique();
            $table->string('series')->nullable();
            $table->string('folio')->nullable();
            $table->string('payment_form', 2);
            $table->string('payment_method', 3)->default('PUE');
            $table->string('cfdi_use', 4);
            $table->string('currency', 3)->default('MXN');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('pdf_url')->nullable();
            $table->string('xml_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('stamped_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
