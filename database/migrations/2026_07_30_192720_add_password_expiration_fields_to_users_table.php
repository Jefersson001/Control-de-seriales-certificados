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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('password_never_expires')->default(true);
            $table->unsignedSmallInteger('password_expiration_days')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'password_never_expires',
                'password_expiration_days',
                'password_changed_at',
            ]);
        });
    }
};
