<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copy every daily closing into a closed cash session, then drop cash_closings.
     */
    public function up(): void
    {
        DB::table('cash_closings')->orderBy('id')->each(function (object $closing): void {
            DB::table('cash_sessions')->insert([
                'opened_by_id' => $closing->user_id,
                'opened_at' => $closing->business_date.' 00:00:00',
                'opening_cash' => $closing->opening_cash,
                'closed_by_id' => $closing->user_id,
                'closed_at' => $closing->closed_at,
                'expected_cash' => $closing->expected_cash,
                'counted_cash' => $closing->counted_cash,
                'cash_difference' => $closing->cash_difference,
                'card_total' => $closing->card_total,
                'transfer_total' => $closing->transfer_total,
                'closing_note' => $closing->notes,
                'status' => 'closed',
                'open_lock' => null,
                'created_at' => $closing->created_at,
                'updated_at' => $closing->updated_at,
            ]);
        });

        Schema::dropIfExists('cash_closings');
    }

    /**
     * Recreates the empty cash_closings table; sessions are not copied back.
     */
    public function down(): void
    {
        Schema::create('cash_closings', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->decimal('opening_cash', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('cash_difference', 12, 2);
            $table->decimal('card_total', 12, 2)->default(0);
            $table->decimal('transfer_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();
        });
    }
};
