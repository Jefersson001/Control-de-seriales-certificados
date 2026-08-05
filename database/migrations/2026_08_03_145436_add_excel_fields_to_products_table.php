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
        Schema::table('products', function (Blueprint $table) {
            $table->string('first_value')->nullable()->after('name');
            $table->string('second_value')->nullable()->after('first_value');
            $table->string('niv', 50)->nullable()->after('second_value');
            $table->unsignedSmallInteger('year')->nullable()->after('niv');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['first_value', 'second_value', 'niv', 'year']);
        });
    }
};
