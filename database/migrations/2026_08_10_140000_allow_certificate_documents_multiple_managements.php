<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_documents', function (Blueprint $table) {
            $table->boolean('imported_without_management')->default(false)->after('uploaded_by');
        });

        Schema::create('certificate_document_management', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certificate_document_id')
                ->constrained('certificate_documents')
                ->cascadeOnDelete();
            $table->foreignId('management_id')
                ->constrained('vehicle_identification_record_management')
                ->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['certificate_document_id', 'management_id'],
                'certificate_document_management_unique',
            );
        });

        DB::table('certificate_documents')
            ->whereNull('management_id')
            ->update(['imported_without_management' => true]);

        DB::table('certificate_documents')
            ->whereNotNull('management_id')
            ->orderBy('id')
            ->each(function (object $document): void {
                DB::table('certificate_document_management')->insertOrIgnore([
                    'certificate_document_id' => $document->id,
                    'management_id' => $document->management_id,
                    'created_at' => $document->created_at,
                    'updated_at' => $document->updated_at,
                ]);
            });

        Schema::table('certificate_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('management_id');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_documents', function (Blueprint $table) {
            $table->foreignId('management_id')
                ->nullable()
                ->constrained('vehicle_identification_record_management')
                ->nullOnDelete();
        });

        DB::table('certificate_document_management')
            ->orderBy('id')
            ->get()
            ->each(function (object $relation): void {
                DB::table('certificate_documents')
                    ->where('id', $relation->certificate_document_id)
                    ->whereNull('management_id')
                    ->update(['management_id' => $relation->management_id]);
            });

        Schema::dropIfExists('certificate_document_management');

        Schema::table('certificate_documents', function (Blueprint $table) {
            $table->dropColumn('imported_without_management');
        });
    }
};
