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
        DB::table('products')->whereNull('brand')->orWhere('brand', '')->update(['brand' => 'bera']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /** Existing Bera assignments cannot be distinguished from backfilled values; retain them on rollback. */
    }
};
