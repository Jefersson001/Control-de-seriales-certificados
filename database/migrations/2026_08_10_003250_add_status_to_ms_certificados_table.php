<?php

use App\CertificateStatus;
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
        Schema::table('ms_certificados', function (Blueprint $table) {
            $table->string('status', 30)
                ->default(CertificateStatus::PendingDispatch->value)
                ->after('niv')
                ->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ms_certificados', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
