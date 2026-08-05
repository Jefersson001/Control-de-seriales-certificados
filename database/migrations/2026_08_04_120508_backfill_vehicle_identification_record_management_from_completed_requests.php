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
        $timestamp = now();

        DB::table('motorcycle_serial_requests')
            ->where('status', 'done')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('vehicle_identification_record_management')
                    ->whereColumn(
                        'vehicle_identification_record_management.motorcycle_serial_request_id',
                        'motorcycle_serial_requests.id',
                    );
            })
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $requestId) use ($timestamp): void {
                DB::table('vehicle_identification_record_management')->insert([
                    'motorcycle_serial_request_id' => $requestId,
                    'status' => 'draft',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Backfilled records may already be in use and must not be removed during rollback.
    }
};
