<?php

namespace App\Support;

/**
 * Pilihan field formulir E-Klaim (mengacu panduan E-Klaim SIMRS DHealth, Juni 2023).
 * Sesuaikan dengan referensi E-Klaim yang berlaku di rumah sakit.
 */
class Eklaim
{
    public const JENIS_RAWAT = [
        'inap' => 'Rawat inap',
        'jalan' => 'Rawat jalan',
    ];

    public const KELAS = [
        '1' => 'Kelas 1',
        '2' => 'Kelas 2',
        '3' => 'Kelas 3',
    ];

    public const KELAS_RAWAT = [
        '1' => 'Kelas 1',
        '2' => 'Kelas 2',
        '3' => 'Kelas 3',
        'vip' => 'VIP',
        'vvip' => 'VVIP',
    ];

    public const CARA_MASUK = [
        'rujukan_fktp' => 'Rujukan FKTP',
        'rujukan_fkrtl' => 'Rujukan FKRTL',
        'rujukan_spesialis' => 'Rujukan dokter spesialis',
        'rawat_jalan' => 'Dari rawat jalan',
        'igd' => 'Dari IGD',
        'rawat_inap' => 'Dari rawat inap',
        'lahir_di_rs' => 'Lahir di RS',
        'lain' => 'Lain-lain',
    ];

    public const CARA_PULANG = [
        'persetujuan_dokter' => 'Atas persetujuan dokter',
        'dirujuk' => 'Dirujuk',
        'aps' => 'Atas permintaan sendiri',
        'meninggal' => 'Meninggal',
        'lain' => 'Lain-lain',
    ];

    /** 18 komponen tarif E-Klaim. Kunci dipakai di kolom JSON `tarif`. */
    public const TARIF = [
        'prosedur_non_bedah' => 'Prosedur non bedah',
        'prosedur_bedah' => 'Prosedur bedah',
        'konsultasi' => 'Konsultasi',
        'tenaga_ahli' => 'Tenaga ahli',
        'keperawatan' => 'Keperawatan',
        'penunjang' => 'Penunjang',
        'radiologi' => 'Radiologi',
        'laboratorium' => 'Laboratorium',
        'pelayanan_darah' => 'Pelayanan darah',
        'rehabilitasi' => 'Rehabilitasi',
        'kamar' => 'Kamar / akomodasi',
        'rawat_intensif' => 'Rawat intensif',
        'obat' => 'Obat',
        'obat_kronis' => 'Obat kronis',
        'obat_kemoterapi' => 'Obat kemoterapi',
        'alkes' => 'Alat kesehatan',
        'bmhp' => 'BMHP',
        'sewa_alat' => 'Sewa alat',
    ];

    /** Lima butir daftar cek manual petugas. */
    public const CHECKLIST = [
        'identitas' => 'Identitas pasien dan No. SEP sama di semua dokumen',
        'diagnosis' => 'Diagnosis utama dan sekunder sesuai resume medis; saran AI sudah dikonfirmasi ke DPJP',
        'tindakan' => 'Tindakan sesuai laporan operasi dan hasil penunjang',
        'ttd' => 'Tanda tangan DPJP dan tanggal pada resume medis lengkap',
        'biaya' => 'Rincian biaya sesuai pelayanan yang tercatat',
    ];

    public static function rupiah(int|float|null $v): string
    {
        return 'Rp ' . number_format((float) $v, 0, ',', '.');
    }
}
