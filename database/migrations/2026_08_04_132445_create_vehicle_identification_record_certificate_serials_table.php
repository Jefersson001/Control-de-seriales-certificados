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
        Schema::create('vehicle_identification_record_certificate_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_id')
                ->constrained('vehicle_identification_record_management_certificates', 'id', 'vircs_certificate_fk')
                ->cascadeOnDelete();
            $table->foreignId('request_serial_id')
                ->nullable()
                ->constrained('motorcycle_serial_request_line_serials', 'id', 'vircs_request_serial_fk')
                ->cascadeOnDelete();
            $table->string('classification');
            $table->string('serial')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique('request_serial_id', 'vircs_request_serial_unique');
            $table->index(['certificate_id', 'classification'], 'certificate_serial_class_index');
            $table->index('serial');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_identification_record_certificate_serials');
    }
};
