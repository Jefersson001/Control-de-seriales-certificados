<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ms_certificados', 'certificate_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->index('created_at');
                $table->index('issued_on');
            });
        }

        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->index('request_date', 'virm_request_date_index');
        });
    }

    public function down(): void
    {
        foreach (['ms_certificados', 'certificate_documents'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropIndex(['created_at']);
                $table->dropIndex(['issued_on']);
            });
        }

        Schema::table('vehicle_identification_record_management', function (Blueprint $table) {
            $table->dropIndex('virm_request_date_index');
        });
    }
};
