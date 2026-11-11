<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('status', 20)->default('antre')->index();
            $table->text('summary')->nullable();
            $table->json('findings')->nullable();        // temuan AI
            $table->json('rule_findings')->nullable();   // temuan aturan konsistensi
            $table->json('suggestions')->nullable();     // saran diagnosis untuk dikonfirmasi ke DPJP
            $table->json('documents_snapshot')->nullable(); // id+sha256 dokumen saat diperiksa
            $table->text('error')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reviews');
    }
};
