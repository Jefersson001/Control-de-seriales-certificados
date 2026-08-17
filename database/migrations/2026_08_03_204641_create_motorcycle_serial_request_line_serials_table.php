<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('motorcycle_serial_request_line_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorcycle_serial_request_line_id')
                ->constrained('motorcycle_serial_request_lines', 'id', 'msrls_line_fk')
                ->cascadeOnDelete();
            $table->string('serial');
            $table->timestamps();
            $table->unique(['motorcycle_serial_request_line_id', 'serial'], 'msrls_line_serial_unique');
            $table->index('serial');
        });

        DB::table('motorcycle_serial_request_lines')
            ->select(['id', 'serials', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->each(function (object $line): void {
                $serials = preg_split('/\R/u', (string) $line->serials) ?: [];

                foreach (array_unique(array_filter(array_map('trim', $serials))) as $serial) {
                    DB::table('motorcycle_serial_request_line_serials')->insert([
                        'motorcycle_serial_request_line_id' => $line->id,
                        'serial' => $serial,
                        'created_at' => $line->created_at,
                        'updated_at' => $line->updated_at,
                    ]);
                }
            });

        Schema::table('motorcycle_serial_request_lines', function (Blueprint $table) {
            $table->dropColumn('serials');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('motorcycle_serial_request_lines', function (Blueprint $table) {
            $table->text('serials')->default('');
        });

        DB::table('motorcycle_serial_request_lines')
            ->select('id')
            ->orderBy('id')
            ->each(function (object $line): void {
                $serials = DB::table('motorcycle_serial_request_line_serials')
                    ->where('motorcycle_serial_request_line_id', $line->id)
                    ->orderBy('id')
                    ->pluck('serial')
                    ->implode("\n");

                DB::table('motorcycle_serial_request_lines')
                    ->where('id', $line->id)
                    ->update(['serials' => $serials]);
            });

        Schema::dropIfExists('motorcycle_serial_request_line_serials');
    }
};
