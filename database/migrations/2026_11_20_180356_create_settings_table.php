<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business data printed on tickets, sale notes and invoices, edited in Configuración.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        DB::table('settings')->insert(collect([
            'business_name' => 'Innovacer',
            'business_address' => null,
            'business_phone' => null,
            'business_rfc' => null,
            'ticket_printer_width' => '80',
        ])->map(fn (?string $value, string $key) => [
            'key' => $key,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ])->values()->all());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
