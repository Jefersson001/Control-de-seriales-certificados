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
        Schema::table('motorcycle_serial_request_lines', function (Blueprint $table) {
            $table->string('source_file_name')->nullable()->after('serials');
            $table->string('source_file_path')->nullable()->after('source_file_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('motorcycle_serial_request_lines', function (Blueprint $table) {
            $table->dropColumn(['source_file_name', 'source_file_path']);
        });
    }
};
