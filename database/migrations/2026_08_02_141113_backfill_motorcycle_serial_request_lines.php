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
        $requests = DB::table('motorcycle_serial_requests')
            ->select(['id', 'product_id', 'quantity', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            DB::table('motorcycle_serial_request_lines')->insert([
                'motorcycle_serial_request_id' => $request->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'serials' => '',
                'created_at' => $request->created_at,
                'updated_at' => $request->updated_at,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Existing single-line data cannot be restored after requests gain multiple lines.
    }
};
