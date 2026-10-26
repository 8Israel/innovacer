<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash customers may have no tax data, and the generic RFC
     * (XAXX010101000) repeats, so RFC is no longer unique.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['rfc']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('rfc', 13)->nullable()->change();
            $table->string('tax_system', 3)->nullable()->change();
            $table->string('cfdi_use', 4)->nullable()->change();
            $table->string('zip_code', 5)->nullable()->change();

            $table->string('phone')->nullable()->after('email');
            $table->string('address')->nullable()->after('phone');
            $table->decimal('credit_limit', 12, 2)->nullable()->after('zip_code');
            $table->decimal('discount_rate', 5, 4)->default(0)->after('credit_limit');
            $table->boolean('is_active')->default(true)->after('discount_rate');

            $table->index('rfc');
        });
    }

    /**
     * Restoring the unique RFC fails if duplicates were created meanwhile.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['rfc']);
            $table->dropColumn(['phone', 'address', 'credit_limit', 'discount_rate', 'is_active']);

            $table->string('rfc', 13)->nullable(false)->change();
            $table->string('tax_system', 3)->nullable(false)->change();
            $table->string('cfdi_use', 4)->nullable(false)->change();
            $table->string('zip_code', 5)->nullable(false)->change();

            $table->unique('rfc');
        });
    }
};
