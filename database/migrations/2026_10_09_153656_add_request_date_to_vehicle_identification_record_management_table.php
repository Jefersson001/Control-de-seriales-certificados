<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->date('request_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->dropColumn('request_date');
        });
    }
};
