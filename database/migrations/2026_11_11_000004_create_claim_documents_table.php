<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claim_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('original_name');
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64)->index();
            $table->unsignedSmallInteger('page_count')->nullable();
            $table->boolean('has_text_layer')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->string('source', 20)->default('unggah'); // unggah | ftp | riwayat
            $table->string('remote_path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_documents');
    }
};
