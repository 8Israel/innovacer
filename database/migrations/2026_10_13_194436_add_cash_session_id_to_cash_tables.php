<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables whose money movements belong to a cash session.
     *
     * @var list<string>
     */
    private array $tables = ['sales', 'sale_payments', 'customer_payments', 'customer_returns', 'cash_movements', 'supplier_payments'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('cash_session_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('cash_session_id');
            });
        }
    }
};
