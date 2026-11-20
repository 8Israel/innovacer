<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A repayment (negative entry) points to the loan (positive entry) it repays.
     */
    public function up(): void
    {
        Schema::table('loan_entries', function (Blueprint $table) {
            $table->foreignId('repays_loan_entry_id')->nullable()->after('inventory_operation_id')->constrained('loan_entries')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repays_loan_entry_id');
        });
    }
};
