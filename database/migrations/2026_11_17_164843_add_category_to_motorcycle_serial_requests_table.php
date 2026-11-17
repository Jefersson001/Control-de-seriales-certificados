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
            $table->string('category')->default('nacional')->after('request_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('motorcycle_serial_requests', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
