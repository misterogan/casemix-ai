<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            // Identitas
            $table->string('nama_pasien');
            $table->string('no_rm', 30)->index();
            $table->string('no_sep', 30)->nullable()->index();
            $table->string('no_peserta', 30)->nullable();
            $table->date('tanggal_lahir')->nullable();
            $table->char('jenis_kelamin', 1)->nullable(); // L/P
            // Pelayanan (field E-Klaim)
            $table->string('jenis_rawat', 10);            // inap | jalan
            $table->string('kelas_hak', 5)->nullable();
            $table->string('kelas_rawat', 5)->nullable();
            $table->boolean('naik_kelas')->default(false);
            $table->boolean('eksekutif')->default(false);
            $table->date('tanggal_masuk');
            $table->date('tanggal_keluar')->nullable();
            $table->string('cara_masuk', 30)->nullable();
            $table->string('cara_pulang', 30)->nullable();
            $table->unsignedInteger('berat_lahir')->nullable();      // gram
            $table->unsignedSmallInteger('rawat_intensif_hari')->nullable();
            $table->unsignedSmallInteger('ventilator_jam')->nullable();
            $table->unsignedTinyInteger('adl_sub_acute')->nullable();
            $table->unsignedTinyInteger('adl_chronic')->nullable();
            $table->boolean('pasien_tb')->default(false);
            $table->string('tekanan_darah', 15)->nullable();
            $table->string('dpjp');
            // Klinis (teks, bukan kode ICD)
            $table->text('diagnosis_utama')->nullable();
            $table->text('diagnosis_sekunder')->nullable();
            $table->text('tindakan')->nullable();
            $table->json('tarif')->nullable();            // 18 komponen
            // Alur kerja
            $table->string('status', 30)->default('belum_koreksi')->index();
            $table->json('checklist')->nullable();
            $table->foreignId('checklist_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checklist_at')->nullable();
            $table->timestamp('content_updated_at')->nullable(); // dasar penentuan "usang"
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tanggal_keluar', 'jenis_rawat']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
