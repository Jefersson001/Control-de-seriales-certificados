<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $importedSerials = DB::table('vehicle_identification_record_certificate_serials as serials')
            ->join('vehicle_identification_record_management_certificates as certificates', 'certificates.id', '=', 'serials.certificate_id')
            ->join('vehicle_identification_record_management as managements', 'managements.id', '=', 'certificates.management_id')
            ->where('managements.category', 'importados')
            ->whereNotNull('serials.imported_at')
            ->select('serials.serial');

        DB::table('ms_certificados')->whereIn('niv', $importedSerials)->update(['category' => 'importados']);

        $requestedSerials = DB::table('motorcycle_serial_request_line_serials as serials')
            ->join('motorcycle_serial_request_lines as lines', 'lines.id', '=', 'serials.motorcycle_serial_request_line_id')
            ->join('vehicle_identification_record_management as managements', 'managements.motorcycle_serial_request_id', '=', 'lines.motorcycle_serial_request_id')
            ->where('managements.category', 'importados')
            ->select('serials.serial');

        DB::table('ms_certificados')->where('codigo', '')->whereIn('niv', $requestedSerials)->update(['category' => 'importados']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** Preserve categories recovered from the source management records on rollback. */
    }
};
