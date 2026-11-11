<?php

namespace App\Enums;

enum Severity: string
{
    case Kritis = 'kritis';
    case Perhatian = 'perhatian';
    case Sesuai = 'sesuai';

    public function label(): string
    {
        return match ($this) {
            self::Kritis => 'Kritis',
            self::Perhatian => 'Perhatian',
            self::Sesuai => 'Sesuai',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Kritis => 'tone-crit',
            self::Perhatian => 'tone-warn',
            self::Sesuai => 'tone-ok',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Kritis => 0,
            self::Perhatian => 1,
            self::Sesuai => 2,
        };
    }
}
