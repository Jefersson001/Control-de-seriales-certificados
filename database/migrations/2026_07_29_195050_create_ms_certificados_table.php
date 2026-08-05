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
        Schema::create('ms_certificados', function (Blueprint $table) {
            $table->id();
            $table->string('no', 50)->index();
            $table->string('marca', 100);
            $table->string('modelo', 100);
            $table->string('tipo', 100);
            $table->string('fabricacion', 100);
            $table->unsignedSmallInteger('anio');
            $table->string('niv', 50)->index();
            $table->string('codigo', 100)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ms_certificados');
    }
};
