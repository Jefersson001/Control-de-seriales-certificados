<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('return_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ms_certificado_id')->constrained('ms_certificados')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['return_id', 'ms_certificado_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
        Schema::dropIfExists('returns');
    }
};
