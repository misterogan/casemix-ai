<?php

namespace App\Enums;

enum ReviewStatus: string
{
    case Antre = 'antre';
    case Berjalan = 'berjalan';
    case Selesai = 'selesai';
    case Gagal = 'gagal';

    public function label(): string
    {
        return match ($this) {
            self::Antre => 'Dalam antrean',
            self::Berjalan => 'Sedang diperiksa',
            self::Selesai => 'Selesai',
            self::Gagal => 'Gagal',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Antre, self::Berjalan], true);
    }
}
