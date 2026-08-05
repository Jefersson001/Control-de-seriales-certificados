<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('motorcycle_serial_request_line_serials')->orderBy('id')->each(function (object $serial): void {
            DB::table('motorcycle_serial_request_line_serials')
                ->where('id', $serial->id)
                ->update(['serial' => strtoupper(trim($serial->serial))]);
        });

        Schema::table('motorcycle_serial_request_line_serials', function (Blueprint $table) {
            $table->dropUnique(['motorcycle_serial_request_line_id', 'serial']);
            $table->unique('serial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('motorcycle_serial_request_line_serials', function (Blueprint $table) {
            $table->dropUnique(['serial']);
            $table->unique(['motorcycle_serial_request_line_id', 'serial']);
        });
    }
};
