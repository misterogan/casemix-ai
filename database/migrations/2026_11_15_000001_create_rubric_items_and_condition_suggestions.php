<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ftp_folder_id')->constrained()->cascadeOnDelete();
            $t->string('kode', 40);
            $t->string('status', 20)->default('kurang'); // lengkap|kurang|tidak_berlaku|diterima
            $t->string('catatan', 255)->nullable();
            $t->string('dokumen')->nullable();
            $t->unsignedInteger('halaman')->nullable();
            $t->json('source_fingerprints')->nullable();
            $t->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('accepted_reason', 500)->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('evaluated_at')->nullable();
            $t->timestamps();
            $t->unique(['ftp_folder_id', 'kode']);
        });

        Schema::create('condition_suggestions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ftp_folder_id')->constrained()->cascadeOnDelete();
            $t->string('kondisi');
            $t->string('bukti', 400)->nullable();
            $t->string('dokumen')->nullable();
            $t->unsignedInteger('halaman')->nullable();
            $t->json('source_fingerprints')->nullable();
            $t->string('status', 20)->default('baru'); // baru|ditanyakan|disetujui|tidak_disetujui|tidak_relevan
            $t->string('catatan_respons', 500)->nullable();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->index(['ftp_folder_id', 'status']);
        });

        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->unsignedSmallInteger('rubric_kurang')->nullable()->after('perhatian_count'); // null = rubrik belum dinilai
            $t->string('suggestions_fingerprint', 64)->nullable()->after('rubric_kurang');
        });
    }

    public function down(): void
    {
        Schema::table('ftp_folders', fn (Blueprint $t) => $t->dropColumn(['rubric_kurang', 'suggestions_fingerprint']));
        Schema::dropIfExists('condition_suggestions');
        Schema::dropIfExists('rubric_items');
    }
};
