<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Impor massal dari folder: metadata bisa belum lengkap (nomor KMK / tanggal berlaku) → ditandai untuk dilengkapi.
        Schema::table('pnpk_dokumen', function (Blueprint $t) {
            $t->boolean('perlu_dilengkapi')->default(false)->index();
            $t->string('nomor_kmk', 120)->nullable()->change();
            $t->date('tanggal_berlaku')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pnpk_dokumen', function (Blueprint $t) {
            $t->dropIndex(['perlu_dilengkapi']);
            $t->dropColumn('perlu_dilengkapi');
        });
    }
};
