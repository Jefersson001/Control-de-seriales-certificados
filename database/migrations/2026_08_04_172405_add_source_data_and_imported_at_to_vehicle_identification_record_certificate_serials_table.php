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
        Schema::table('vehicle_identification_record_certificate_serials', function (Blueprint $table) {
            $table->json('source_data')->nullable();
            $table->timestamp('imported_at')->nullable()->index('vircs_imported_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vehicle_identification_record_certificate_serials', function (Blueprint $table) {
            $table->dropColumn(['source_data', 'imported_at']);
        });
    }
};
