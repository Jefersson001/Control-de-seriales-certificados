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
        Schema::create('niv_compliance_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category')->default('nacional')->index();
            $table->string('certificate_number')->nullable()->index();
            $table->string('document_reference')->nullable();
            $table->string('company_name')->nullable();
            $table->string('company_rif')->nullable();
            $table->string('refeciv')->nullable();
            $table->string('serial_pattern', 17)->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->string('model')->nullable();
            $table->string('series_version')->nullable();
            $table->string('manufacturing_years')->nullable();
            $table->unsignedSmallInteger('model_year')->nullable();
            $table->string('country_of_origin')->nullable();
            $table->string('vehicle_class')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('vehicle_use')->nullable();
            $table->string('wmi', 3)->nullable();
            $table->string('iim')->nullable();
            $table->text('observations')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->boolean('has_erratum')->default(false);
            $table->string('original_series_version')->nullable();
            $table->string('erratum_reference')->nullable();
            $table->date('erratum_issued_on')->nullable();
            $table->text('erratum_notes')->nullable();
            $table->string('original_file_name');
            $table->string('file_path');
            $table->string('file_hash', 64)->unique();
            $table->longText('extracted_text')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('niv_compliance_certificates');
    }
};
