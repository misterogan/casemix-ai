<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Antre = 'antre';
    case Berjalan = 'berjalan';
    case Selesai = 'selesai';
    case Gagal = 'gagal';
    case Dibatalkan = 'dibatalkan'; // tidak diproses karena langkah sebelumnya gagal

    public function label(): string
    {
        return match ($this) {
            self::Antre => 'Dalam antrean',
            self::Berjalan => 'Sedang diperiksa',
            self::Selesai => 'Selesai',
            self::Gagal => 'Gagal',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Antre, self::Berjalan], true);
    }
}
