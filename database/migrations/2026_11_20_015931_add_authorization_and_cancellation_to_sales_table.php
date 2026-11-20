<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who authorized a sale's extra discount or credit over the limit, and who
     * cancelled it, each with their reason. user_id stays "who charged the sale".
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('authorized_by_id')->nullable()->after('user_id')->constrained('users')->restrictOnDelete();
            $table->string('authorization_reason')->nullable()->after('authorized_by_id');
            $table->foreignId('canceled_by_id')->nullable()->after('canceled_at')->constrained('users')->restrictOnDelete();
            $table->string('cancel_reason')->nullable()->after('canceled_by_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authorized_by_id');
            $table->dropConstrainedForeignId('canceled_by_id');
            $table->dropColumn(['authorization_reason', 'cancel_reason']);
        });
    }
};
