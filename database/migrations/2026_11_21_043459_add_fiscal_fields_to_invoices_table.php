<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('type')->default('income')->after('status');
            $table->string('uuid', 36)->nullable()->unique()->after('facturapi_id');
            $table->string('cancellation_reason', 2)->nullable()->after('canceled_at');
            $table->string('substitution_uuid', 36)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['type', 'uuid', 'cancellation_reason', 'substitution_uuid']);
        });
    }
};
