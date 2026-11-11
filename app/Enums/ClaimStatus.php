<?php

namespace App\Enums;

enum ClaimStatus: string
{
    case BelumKoreksi = 'belum_koreksi';
    case PerluDilengkapi = 'perlu_dilengkapi';
    case SudahKoreksi = 'sudah_koreksi';
    case Disetujui = 'disetujui';
    case SepBelumAda = 'sep_belum_ada';

    public function label(): string
    {
        return match ($this) {
            self::BelumKoreksi => 'Belum Koreksi',
            self::PerluDilengkapi => 'Perlu Dilengkapi',
            self::SudahKoreksi => 'Sudah Koreksi',
            self::Disetujui => 'Disetujui',
            self::SepBelumAda => 'No. SEP belum ada',
        };
    }

    /** Kelas warna lencana status (lihat resources/css/app.css). */
    public function tone(): string
    {
        return match ($this) {
            self::BelumKoreksi => 'tone-neutral',
            self::PerluDilengkapi => 'tone-warn',
            self::SudahKoreksi => 'tone-ok',
            self::Disetujui => 'tone-done',
            self::SepBelumAda => 'tone-crit',
        };
    }
}
