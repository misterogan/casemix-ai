<?php

namespace App\Services\Import;

use App\Enums\DocumentType;

/**
 * Menebak jenis dokumen dari nama file dan identitas pasien dari nama folder FTP SIMRS.
 * Pola folder: NoRM_NAMA PASIEN_NoSEP (mis. 003660_NAMA PASIEN_0030R0011126V000133).
 * Tambahkan kata kunci baru di TYPE_PATTERNS bila SIMRS memakai nama file lain.
 */
class DocumentGuesser
{
    /** Urutan penting: pola yang lebih spesifik di atas. */
    private const TYPE_PATTERNS = [
        'sep' => '/\bsep\b|surat eligibilitas/',
        'resume_medis' => '/resume|ringkasan pulang|discharge/',
        'rincian_biaya' => '/billing|rincian|biaya|tagihan|kwitansi|kuitansi|invoice|bill\b/',
        'laporan_operasi' => '/operasi|lapop|lap op\b|laporan op\b/',
        'laboratorium' => '/\blab\b|laborat|darah rutin|hematologi|kimia klinik/',
        'radiologi' => '/radiolog|rontgen|thorax|torax|\busg\b|ct scan|\bct\b|\bmri\b|expertise|ekspertise|\bro\b/',
        'daftar_obat' => '/resep|\bobat\b|farmasi/',
    ];

    public static function type(string $fileName): DocumentType
    {
        $n = self::normalize(pathinfo($fileName, PATHINFO_FILENAME));
        foreach (self::TYPE_PATTERNS as $type => $pattern) {
            if (preg_match($pattern, $n)) {
                return DocumentType::from($type);
            }
        }

        return DocumentType::Tambahan;
    }

    /** @param  array<string>  $fileNames */
    public static function careType(array $fileNames): ?string
    {
        $all = self::normalize(implode(' | ', $fileNames));
        $jalan = (bool) preg_match('/rawat jalan|\brajal\b|\birj\b|poli/', $all);
        $inap = (bool) preg_match('/rawat inap|\branap\b|\birna\b/', $all);

        return match (true) {
            $jalan && ! $inap => 'jalan',
            $inap && ! $jalan => 'inap',
            default => null,
        };
    }

    /** @return array{no_rm: string, nama: string, no_sep: ?string}|null */
    public static function parseFolder(string $folderName): ?array
    {
        $name = trim(preg_replace('/\s+/', ' ', $folderName));
        if (preg_match('/^(\d{3,})_(.+)_([0-9A-Z]{19})$/i', $name, $m)) {
            return ['no_rm' => $m[1], 'nama' => self::cleanName($m[2]), 'no_sep' => strtoupper($m[3])];
        }
        if (preg_match('/^(\d{3,})_(.+)$/', $name, $m)) {
            return ['no_rm' => $m[1], 'nama' => self::cleanName($m[2]), 'no_sep' => null];
        }

        return null;
    }

    private static function cleanName(string $n): string
    {
        return trim(preg_replace('/\s+/', ' ', rtrim(trim($n), '. ')));
    }

    private static function normalize(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[_\-.]+/', ' ', mb_strtolower($s))));
    }
}
