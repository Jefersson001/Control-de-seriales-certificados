<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vehicle_identification_record_management_certificates', 'certificate_documents', 'ms_certificados'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->date('issued_on')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['vehicle_identification_record_management_certificates', 'certificate_documents', 'ms_certificados'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('issued_on');
            });
        }
    }
};
