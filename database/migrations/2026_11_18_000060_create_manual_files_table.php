<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->string('sumber', 10)->default('ftp')->index();
            $t->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->text('catatan')->nullable();
            $t->date('tgl_pulang')->nullable();
        });
        Schema::create('manual_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ftp_folder_id')->constrained()->cascadeOnDelete();
            $t->string('nama_asli', 255);
            $t->string('path', 500);
            $t->string('mime', 60);
            $t->unsignedBigInteger('ukuran');
            $t->char('sha256', 64);
            $t->string('jenis_dokumen', 20)->nullable();
            $t->foreignId('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->string('alasan_hapus', 500)->nullable();
            $t->timestamp('dimusnahkan_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->index(['ftp_folder_id', 'sha256']);
        });
        Schema::table('folder_analyses', function (Blueprint $t) {
            $t->boolean('termasuk_manual')->default(false);
        });
        Schema::table('folder_documents', function (Blueprint $t) {
            $t->unsignedBigInteger('manual_file_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('folder_documents', fn (Blueprint $t) => $t->dropColumn('manual_file_id'));
        Schema::table('folder_analyses', fn (Blueprint $t) => $t->dropColumn('termasuk_manual'));
        Schema::dropIfExists('manual_files');
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('dibuat_oleh');
            $t->dropColumn(['sumber', 'catatan', 'tgl_pulang']);
        });
    }
};
