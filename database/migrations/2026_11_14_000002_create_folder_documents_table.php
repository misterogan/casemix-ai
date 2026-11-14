<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hasil baca AI per dokumen dalam satu analisa folder; dipakai ulang bila file tidak berubah.
        Schema::create('folder_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folder_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ftp_folder_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('file_index');
            $table->string('name', 255);
            $table->string('file_fingerprint', 100);
            $table->string('doc_type', 40)->nullable();
            $table->string('status', 20)->default('antre')->index();
            $table->boolean('reused')->default(false);
            $table->json('summary')->nullable();
            $table->json('findings')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost_usd', 12, 6)->nullable();
            $table->string('model', 100)->nullable();
            $table->foreignId('ai_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['ftp_folder_id', 'name', 'file_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_documents');
    }
};
