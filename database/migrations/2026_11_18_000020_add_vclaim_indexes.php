<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Indeks untuk analisis Ringkasan Klaim BPJS (severity, kategori, pending, mutu). */
return new class extends Migration
{
    private const INDEKS = [
        'vclaim_klaim' => [
            'vclaim_klaim_tgl_status_idx' => ['tgl_pulang', 'status_kode'],
            'vclaim_klaim_inacbg_idx' => ['inacbg_kode'],
            'vclaim_klaim_jns_tgl_idx' => ['jns_pelayanan', 'tgl_pulang'],
        ],
        'vclaim_kunjungan' => [
            'vclaim_kunjungan_kartu_tgl_idx' => ['no_kartu', 'tgl_sep'],
            'vclaim_kunjungan_diagnosa_idx' => ['diagnosa'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEKS as $tabel => $daftar) {
            foreach ($daftar as $nama => $kolom) {
                if (! Schema::hasIndex($tabel, $nama) && ! Schema::hasIndex($tabel, $kolom)) {
                    Schema::table($tabel, fn (Blueprint $t) => $t->index($kolom, $nama));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEKS as $tabel => $daftar) {
            foreach (array_keys($daftar) as $nama) {
                if (Schema::hasIndex($tabel, $nama)) {
                    Schema::table($tabel, fn (Blueprint $t) => $t->dropIndex($nama));
                }
            }
        }
    }
};
