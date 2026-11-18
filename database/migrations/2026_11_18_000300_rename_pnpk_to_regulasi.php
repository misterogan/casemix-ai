<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABEL = ['pnpk_dokumen' => 'regulasi_dokumen', 'pnpk_halaman' => 'regulasi_halaman', 'pnpk_icd' => 'regulasi_icd', 'pnpk_butir' => 'regulasi_butir'];

    /** PNPK → Acuan regulasi umum. Hanya rename + kolom baru; status, diperiksa_manusia, halaman_pdf tidak disentuh. */
    public function up(): void
    {
        foreach (self::TABEL as $lama => $baru) {
            Schema::rename($lama, $baru);
        }

        Schema::table('regulasi_dokumen', function (Blueprint $t) {
            $t->string('jenis', 20)->default('kmk_pnpk')->index()->after('judul');
            $t->string('nomor', 150)->nullable()->after('jenis');
            $t->json('cakupan')->nullable()->after('nomor');
            $t->string('mencabut_teks', 255)->nullable();
            $t->unsignedBigInteger('mencabut_id')->nullable()->index();
        });
        // FK hanya di MySQL: di SQLite menambah FK membangun ulang tabel dan memicu cascade ke butir/halaman/ICD.
        if (DB::getDriverName() === 'mysql') {
            Schema::table('regulasi_dokumen', fn (Blueprint $t) => $t->foreign('mencabut_id')->references('id')->on('regulasi_dokumen')->nullOnDelete());
        }
        DB::table('regulasi_dokumen')->update(['jenis' => 'kmk_pnpk', 'nomor' => DB::raw('nomor_kmk'), 'cakupan' => json_encode(['medis'])]);
        if (DB::getDriverName() === 'mysql') {
            Schema::table('regulasi_dokumen', fn (Blueprint $t) => $t->fullText(['judul', 'nomor']));
        }

        Schema::table('regulasi_butir', function (Blueprint $t) {
            $t->string('pasal', 150)->nullable()->after('halaman_pdf');
            $t->json('cakupan_icd')->nullable();
            $t->json('cakupan_icd9')->nullable();
            $t->json('cakupan_inacbg')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('regulasi_butir', fn (Blueprint $t) => $t->dropColumn(['pasal', 'cakupan_icd', 'cakupan_icd9', 'cakupan_inacbg']));
        Schema::table('regulasi_dokumen', function (Blueprint $t) {
            if (DB::getDriverName() === 'mysql') {
                $t->dropFullText(['judul', 'nomor']);
            }
            if (DB::getDriverName() === 'mysql') {
                $t->dropForeign(['mencabut_id']);
            }
            $t->dropIndex(['mencabut_id']);
            $t->dropColumn('mencabut_id');
            $t->dropIndex(['jenis']);
            $t->dropColumn(['jenis', 'nomor', 'cakupan', 'mencabut_teks']);
        });
        foreach (array_reverse(self::TABEL) as $lama => $baru) {
            Schema::rename($baru, $lama);
        }
    }
};
