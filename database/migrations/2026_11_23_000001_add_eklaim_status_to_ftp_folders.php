<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Status klaim di E-Klaim (hanya dibaca lewat get_claim_data) + tanda manual "sudah diajukan". Aditif. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->string('eklaim_status', 20)->nullable()->index(); // null | final | terkirim
            $t->dateTime('eklaim_final_at')->nullable();
            $t->dateTime('eklaim_terkirim_at')->nullable();
            $t->dateTime('eklaim_dicek_at')->nullable()->index();
            $t->unsignedInteger('eklaim_gagal_cek')->default(0);
            $t->dateTime('diajukan_manual_at')->nullable();
            $t->foreignId('diajukan_manual_oleh')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('diajukan_manual_oleh');
            $t->dropIndex(['eklaim_status']);
            $t->dropIndex(['eklaim_dicek_at']);
            $t->dropColumn(['eklaim_status', 'eklaim_final_at', 'eklaim_terkirim_at', 'eklaim_dicek_at', 'eklaim_gagal_cek', 'diajukan_manual_at']);
        });
    }
};
