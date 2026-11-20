<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn each payment's single sale_id into an allocation, then drop the column.
     */
    public function up(): void
    {
        DB::table('customer_payments')->whereNotNull('sale_id')->orderBy('id')->each(function (object $payment): void {
            DB::table('customer_payment_allocations')->insert([
                'customer_payment_id' => $payment->id,
                'sale_id' => $payment->sale_id,
                'amount' => $payment->amount,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        });

        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }

    /**
     * Restores sale_id from each payment's first allocation; payments spread over
     * several sales keep only the first one.
     */
    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('customer_destination_id')->constrained()->restrictOnDelete();
        });

        DB::table('customer_payment_allocations')
            ->selectRaw('customer_payment_id, MIN(sale_id) as sale_id')
            ->groupBy('customer_payment_id')
            ->orderBy('customer_payment_id')
            ->each(function (object $allocation): void {
                DB::table('customer_payments')->where('id', $allocation->customer_payment_id)->update(['sale_id' => $allocation->sale_id]);
            });

        DB::table('customer_payment_allocations')->delete();
    }
};
