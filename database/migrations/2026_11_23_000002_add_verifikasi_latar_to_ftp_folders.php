<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Verifikasi di latar belakang yang bisa dijeda/dilanjutkan: status & progres per berkas (aditif). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->string('verifikasi_status', 12)->nullable()->index(); // antre|berjalan|menjeda|dijeda|selesai|gagal
            $t->string('verifikasi_file_aktif')->nullable();
            $t->unsignedSmallInteger('verifikasi_file_selesai')->default(0);
            $t->unsignedSmallInteger('verifikasi_file_total')->default(0);
            $t->timestamp('verifikasi_heartbeat_at')->nullable();
            $t->string('verifikasi_pesan', 500)->nullable();
            $t->string('verifikasi_token', 26)->nullable(); // rantai job yang sah; job dengan token lain berhenti
        });
    }

    public function down(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->dropIndex(['verifikasi_status']);
            $t->dropColumn(['verifikasi_status', 'verifikasi_file_aktif', 'verifikasi_file_selesai', 'verifikasi_file_total',
                'verifikasi_heartbeat_at', 'verifikasi_pesan', 'verifikasi_token']);
        });
    }
};
