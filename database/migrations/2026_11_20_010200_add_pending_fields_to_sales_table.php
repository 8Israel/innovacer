<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pending (held) sale has no folio and no sale date until it is charged.
     */
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('folio')->nullable()->change();
            $table->timestamp('sold_at')->nullable()->change();
            $table->timestamp('held_at')->nullable()->after('sold_at');
            $table->string('hold_note')->nullable()->after('held_at');
        });
    }

    /**
     * Fails if pending sales without folio or sale date still exist.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['held_at', 'hold_note']);
            $table->string('folio')->nullable(false)->change();
            $table->timestamp('sold_at')->nullable(false)->change();
        });
    }
};
