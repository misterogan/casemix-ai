<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Usulan isian data klaim yang dibaca AI dari dokumen. Tidak pernah diterapkan tanpa konfirmasi petugas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('status', 20)->default('antre')->index();
            $table->json('suggestions')->nullable();        // field => {nilai, dokumen, keyakinan}
            $table->json('documents_snapshot')->nullable(); // id+sha256 dokumen yang dibaca
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('applied_fields')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_extractions');
    }
};
