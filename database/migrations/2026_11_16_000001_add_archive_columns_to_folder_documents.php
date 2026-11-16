<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Salinan terenkripsi dokumen yang dibaca AI: riwayat dan pratinjau tetap ada walau file dipindah dari FTP. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_documents', function (Blueprint $t) {
            $t->string('stored_path')->nullable()->index();
            $t->string('stored_sha256', 64)->nullable();
            $t->unsignedBigInteger('stored_size')->nullable();
            $t->timestamp('stored_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('folder_documents', function (Blueprint $t) {
            $t->dropIndex(['stored_path']);
            $t->dropColumn(['stored_path', 'stored_sha256', 'stored_size', 'stored_at']);
        });
    }
};
