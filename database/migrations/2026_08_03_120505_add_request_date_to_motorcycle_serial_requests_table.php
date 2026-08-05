<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('motorcycle_serial_requests', function (Blueprint $table) {
            $table->date('request_date')->nullable()->after('serial_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('motorcycle_serial_requests', function (Blueprint $table) {
            $table->dropColumn('request_date');
        });
    }
};
