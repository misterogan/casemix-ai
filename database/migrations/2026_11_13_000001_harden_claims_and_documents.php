<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. Field identitas/pelayanan boleh kosong, supaya impor FTP tidak lagi mengisi nilai tebakan
 *    (tanggal hari impor, DPJP "-", rawat inap). Field kosong menahan persetujuan sampai diisi petugas.
 * 2. No. SEP unik di level database (sebelumnya hanya divalidasi di form).
 * 3. Dokumen dihapus secara lunak (soft delete) agar bukti yang pernah diperiksa tidak hilang.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dupes = DB::table('claims')->whereNotNull('no_sep')
            ->select('no_sep', DB::raw('count(*) as n'))->groupBy('no_sep')->having('n', '>', 1)->pluck('no_sep');
        if ($dupes->isNotEmpty()) {
            throw new RuntimeException('Ada No. SEP ganda di tabel claims: ' . $dupes->implode(', ')
                . '. Gabungkan atau perbaiki berkas tersebut dulu, lalu jalankan migrasi lagi.');
        }

        // Data lama hasil impor yang masih berisi penanda tebakan dikosongkan agar ditangkap aturan.
        DB::table('claims')->where('dpjp', '-')->update(['dpjp' => '']);
        DB::table('claims')->where('nama_pasien', 'Belum dikenali')->update(['nama_pasien' => '']);

        Schema::table('claims', function (Blueprint $table) {
            $table->string('nama_pasien')->nullable()->change();
            $table->string('jenis_rawat', 10)->nullable()->change();
            $table->date('tanggal_masuk')->nullable()->change();
            $table->string('dpjp')->nullable()->change();
            $table->unique('no_sep');
        });

        DB::table('claims')->where('dpjp', '')->update(['dpjp' => null]);
        DB::table('claims')->where('nama_pasien', '')->update(['nama_pasien' => null]);

        Schema::table('claim_documents', function (Blueprint $table) {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('claim_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deleted_by');
            $table->dropSoftDeletes();
        });
        Schema::table('claims', function (Blueprint $table) {
            $table->dropUnique(['no_sep']);
        });
    }
};
