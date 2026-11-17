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
        foreach (['nacional', 'importados'] as $category) {
            DB::table('vehicle_identification_record_management')
                ->whereIn('motorcycle_serial_request_id', DB::table('motorcycle_serial_requests')->select('id')->where('category', $category))
                ->update(['category' => $category]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** Preserve the categories copied from the source requests on rollback. */
    }
};
