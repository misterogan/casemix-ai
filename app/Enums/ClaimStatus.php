<?php

namespace App\Enums;

/**
 * Status berkas di Casemix AI. Nilai di database tetap (belum_koreksi, sudah_koreksi) agar data lama aman,
 * tetapi labelnya sengaja berbeda dari status E-Klaim Enigma: di Enigma "Sudah Koreksi" berarti koding
 * sudah disimpan, sedangkan di sini artinya berkas sudah diperiksa AI.
 */
enum ClaimStatus: string
{
    case BelumDiperiksa = 'belum_koreksi';
    case PerluDilengkapi = 'perlu_dilengkapi';
    case SudahDiperiksa = 'sudah_koreksi';
    case Disetujui = 'disetujui';
    case SepBelumAda = 'sep_belum_ada';

    public function label(): string
    {
        return match ($this) {
            self::BelumDiperiksa => 'Belum Diperiksa',
            self::PerluDilengkapi => 'Perlu Dilengkapi',
            self::SudahDiperiksa => 'Sudah Diperiksa',
            self::Disetujui => 'Disetujui',
            self::SepBelumAda => 'No. SEP belum ada',
        };
    }

    /** Kelas warna lencana status (lihat resources/css/app.css). */
    public function tone(): string
    {
        return match ($this) {
            self::BelumDiperiksa => 'tone-neutral',
            self::PerluDilengkapi => 'tone-warn',
            self::SudahDiperiksa => 'tone-ok',
            self::Disetujui => 'tone-done',
            self::SepBelumAda => 'tone-crit',
        };
    }
}
