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
        Schema::create('vehicle_identification_record_management_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('management_id')
                ->constrained('vehicle_identification_record_management')
                ->cascadeOnDelete();
            $table->string('control_number');
            $table->string('original_file_name');
            $table->string('file_path');
            $table->string('file_hash', 64);
            $table->unsignedInteger('valid_occurrence_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->timestamp('analyzed_at');
            $table->timestamps();
            $table->unique(['management_id', 'file_hash'], 'management_cert_file_unique');
            $table->index(['management_id', 'control_number'], 'management_cert_control_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_identification_record_management_certificates');
    }
};
