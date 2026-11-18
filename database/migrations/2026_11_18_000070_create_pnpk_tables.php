<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PNPK (KMK): PDF resmi = sumber kebenaran; AI hanya mengusulkan, manusia menyetujui.
        Schema::create('pnpk_dokumen', function (Blueprint $t) {
            $t->id();
            $t->string('judul', 255);
            $t->string('nomor_kmk', 120);
            $t->unsignedSmallInteger('tahun');
            $t->date('tanggal_berlaku');
            $t->string('status', 10)->default('berlaku')->index(); // berlaku|diganti|dicabut
            $t->foreignId('diganti_oleh_id')->nullable()->constrained('pnpk_dokumen')->nullOnDelete();
            $t->string('sumber_url', 500)->nullable();
            $t->string('path', 255);
            $t->char('sha256', 64)->unique();
            $t->unsignedInteger('jumlah_halaman')->nullable();
            $t->string('status_proses', 12)->default('antre'); // antre|ekstrak|usulan|siap_tinjau|selesai|gagal
            $t->text('error')->nullable();
            $t->foreignId('diunggah_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('pnpk_halaman', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dokumen_id')->constrained('pnpk_dokumen')->cascadeOnDelete();
            $t->unsignedInteger('halaman');
            $t->longText('teks');
            $t->unique(['dokumen_id', 'halaman']);
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $t->fullText('teks');
            }
        });

        Schema::create('pnpk_icd', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dokumen_id')->constrained('pnpk_dokumen')->cascadeOnDelete();
            $t->string('kode', 12)->index();
            $t->string('sumber', 6); // manual|ai
            $t->string('status', 10)->default('usulan'); // usulan|disetujui|ditolak
            $t->string('alasan_tolak', 255)->nullable();
            $t->foreignId('ditinjau_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('ditinjau_at')->nullable();
            $t->timestamps();
            $t->unique(['dokumen_id', 'kode']);
        });

        Schema::create('pnpk_butir', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dokumen_id')->constrained('pnpk_dokumen')->cascadeOnDelete();
            $t->string('jenis', 30);
            $t->string('ringkasan', 300);
            $t->text('kutipan');
            $t->unsignedInteger('halaman');
            $t->string('level_bukti', 40)->nullable();
            $t->boolean('cocok_kutipan')->default(false);
            $t->string('status', 10)->default('usulan')->index(); // usulan|disetujui|ditolak
            $t->string('alasan_tolak', 255)->nullable();
            $t->foreignId('ditinjau_oleh')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('ditinjau_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnpk_butir');
        Schema::dropIfExists('pnpk_icd');
        Schema::dropIfExists('pnpk_halaman');
        Schema::dropIfExists('pnpk_dokumen');
    }
};
