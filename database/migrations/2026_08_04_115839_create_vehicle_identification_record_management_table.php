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
        Schema::create('vehicle_identification_record_management', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorcycle_serial_request_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('status')->default('draft')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_identification_record_management');
    }
};
