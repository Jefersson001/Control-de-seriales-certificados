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
        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->string('category')->default('nacional')->after('motorcycle_serial_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
