<?php

namespace App\Enums;

enum DocumentType: string
{
    case Sep = 'sep';
    case ResumeMedis = 'resume_medis';
    case RincianBiaya = 'rincian_biaya';
    case Laboratorium = 'laboratorium';
    case Radiologi = 'radiologi';
    case LaporanOperasi = 'laporan_operasi';
    case DaftarObat = 'daftar_obat';
    case Tambahan = 'tambahan';

    public function label(): string
    {
        return match ($this) {
            self::Sep => 'SEP',
            self::ResumeMedis => 'Resume medis',
            self::RincianBiaya => 'Rincian biaya',
            self::Laboratorium => 'Hasil laboratorium',
            self::Radiologi => 'Hasil radiologi',
            self::LaporanOperasi => 'Laporan operasi',
            self::DaftarObat => 'Daftar obat',
            self::Tambahan => 'Dokumen tambahan',
        };
    }

    public function required(): bool
    {
        return in_array($this, [self::Sep, self::ResumeMedis, self::RincianBiaya], true);
    }

    /** @return array<self> */
    public static function requiredTypes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->required()));
    }
}
