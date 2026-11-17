<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Antre = 'antre';
    case Berjalan = 'berjalan';
    case Selesai = 'selesai';
    case Gagal = 'gagal';
    case Dibatalkan = 'dibatalkan'; // tidak diproses karena langkah sebelumnya gagal
    case Diarsipkan = 'diarsipkan'; // hanya salinan arsip otomatis saat sinkron; belum dibaca AI

    public function label(): string
    {
        return match ($this) {
            self::Antre => 'Dalam antrean',
            self::Berjalan => 'Sedang diperiksa',
            self::Selesai => 'Selesai',
            self::Gagal => 'Gagal',
            self::Dibatalkan => 'Dibatalkan',
            self::Diarsipkan => 'Diarsipkan (belum dianalisa)',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Antre, self::Berjalan], true);
    }
}
