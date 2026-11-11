<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 40);            // openai_compatible | anthropic
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();      // dienkripsi (cast encrypted)
            $table->string('model_fast');             // pemilahan dokumen
            $table->string('model_standard');         // pemeriksaan berkas
            $table->boolean('supports_images')->default(true);
            $table->unsignedInteger('max_tokens')->default(4096);
            $table->unsignedInteger('timeout')->default(120);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('data_location')->nullable(); // catatan lokasi pemrosesan data
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
