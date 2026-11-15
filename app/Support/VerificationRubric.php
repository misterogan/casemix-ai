<?php

namespace App\Support;

use App\Models\FtpFolder;

/**
 * Rubrik tetap 14 butir untuk verifikasi folder. Tidak ada butir di luar daftar ini.
 * Tiap butir punya jenis dokumen sumber: hanya bila file sumber berubah, butir yang sudah terkunci dinilai ulang.
 */
final class VerificationRubric
{
    /** kode => [label, kelompok, wajib, jenis dokumen sumber] */
    public const ITEMS = [
        'dok_sep' => ['SEP ada di folder', 'Kelengkapan dokumen', true, ['sep']],
        'dok_resume' => ['Resume medis ada di folder', 'Kelengkapan dokumen', true, ['resume']],
        'dok_biaya' => ['Rincian biaya ada di folder', 'Kelengkapan dokumen', true, ['biaya']],
        'id_nama_rm' => ['Nama dan No. RM sama di semua dokumen', 'Identitas', true, ['sep', 'resume', 'biaya']],
        'id_sep' => ['No. SEP terbaca dan sesuai', 'Identitas', true, ['sep']],
        'res_tanggal' => ['Tanggal masuk dan keluar sesuai SEP', 'Resume medis', true, ['resume', 'sep']],
        'res_diagnosis_utama' => ['Diagnosis utama tertulis', 'Resume medis', true, ['resume']],
        'res_ttd_dpjp' => ['Tanda tangan dan nama DPJP', 'Resume medis', true, ['resume']],
        'res_cara_pulang' => ['Cara pulang tertulis', 'Resume medis', true, ['resume']],
        'bukti_diagnosis' => ['Diagnosis didukung bukti klinis', 'Bukti pendukung', true, ['resume', 'penunjang', 'lainnya']],
        'bukti_tindakan' => ['Tindakan/prosedur tercatat', 'Bukti pendukung', false, ['resume', 'bedah', 'lainnya']],
        'bukti_penunjang' => ['Hasil penunjang (lab/radiologi) bila disebut', 'Bukti pendukung', false, ['resume', 'penunjang']],
        'bukti_bedah' => ['Laporan operasi bila ada tindakan bedah', 'Bukti pendukung', false, ['resume', 'bedah']],
        'aturan_eklaim' => ['Lolos aturan E-Klaim', 'Aturan', true, ['sep', 'resume', 'biaya', 'penunjang', 'bedah', 'lainnya']],
    ];

    /** Butir yang diisi tanpa AI. */
    public const RULE_CODES = ['dok_sep', 'dok_resume', 'dok_biaya', 'aturan_eklaim'];

    public const STATUSES = ['lengkap' => 'Lengkap', 'kurang' => 'Kurang', 'tidak_berlaku' => 'Tidak berlaku', 'diterima' => 'Diterima'];

    /** Status yang mengunci butir selama dokumen sumbernya tidak berubah. */
    public const LOCKED = ['lengkap', 'tidak_berlaku', 'diterima'];

    public static function codes(): array
    {
        return array_keys(self::ITEMS);
    }

    public static function aiCodes(): array
    {
        return array_values(array_diff(self::codes(), self::RULE_CODES));
    }

    public static function label(string $kode): string
    {
        return self::ITEMS[$kode][0] ?? $kode;
    }

    public static function group(string $kode): string
    {
        return self::ITEMS[$kode][1] ?? '';
    }

    public static function required(string $kode): bool
    {
        return (bool) (self::ITEMS[$kode][2] ?? false);
    }

    /** Jenis dokumen menurut nama file. */
    public static function docType(string $name): string
    {
        $n = mb_strtolower(pathinfo($name, PATHINFO_FILENAME));

        return match (true) {
            (bool) preg_match('/\bsep\b|surat eligib/', $n) => 'sep',
            str_contains($n, 'resume') => 'resume',
            (bool) preg_match('/billing|biaya|rincian|tagihan|kwitansi|kuitansi/', $n) => 'biaya',
            (bool) preg_match('/operasi|bedah|\bop\b|anestesi/', $n) => 'bedah',
            (bool) preg_match('/\blab|radiolog|rontgen|ro thorax|\bekg|\busg|\bct\b|\bmri\b|penunjang|patologi/', $n) => 'penunjang',
            default => 'lainnya',
        };
    }

    /** Ada file dengan jenis ini di folder? */
    public static function hasType(FtpFolder $folder, string $type): bool
    {
        return collect($folder->files ?? [])->contains(fn ($f) => self::docType($f['name']) === $type);
    }

    /** Sidik file sumber per jenis dokumen: berubah bila file jenis itu ditambah, dihapus, atau diganti. */
    public static function fingerprints(FtpFolder $folder, string $kode): array
    {
        $files = collect($folder->files ?? []);
        $out = [];
        foreach (self::ITEMS[$kode][3] ?? [] as $type) {
            $of = $files->filter(fn ($f) => self::docType($f['name']) === $type)->values()->all();
            $out[$type] = $of ? FtpFolder::fingerprintOf($of) : null;
        }
        ksort($out);

        return $out;
    }

    /** Sidik satu file (dipakai saran DPJP); bila tidak cocok dengan file mana pun: sidik seluruh folder. */
    public static function fileFingerprint(FtpFolder $folder, ?string $dokumen): array
    {
        $f = collect($folder->files ?? [])->firstWhere('name', $dokumen);

        return $f ? [$f['name'] => FtpFolder::fingerprintOf([$f])] : ['*' => $folder->fingerprint];
    }

    /** Potong teks menjadi maksimal N kata. */
    public static function words(?string $text, int $max): ?string
    {
        $w = preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
        if (! $w) {
            return null;
        }

        return implode(' ', array_slice($w, 0, $max)) . (count($w) > $max ? '…' : '');
    }

    /** Cocokkan nama dokumen dari AI ke nama file di folder (tidak peka huruf, boleh sebagian). */
    public static function matchFile(FtpFolder $folder, ?string $dokumen): ?string
    {
        $d = mb_strtolower(trim((string) $dokumen));
        if ($d === '') {
            return null;
        }
        $names = collect($folder->files ?? [])->pluck('name');

        return $names->first(fn ($n) => mb_strtolower($n) === $d)
            ?? $names->first(fn ($n) => str_contains(mb_strtolower(pathinfo($n, PATHINFO_FILENAME)), $d) || str_contains($d, mb_strtolower(pathinfo($n, PATHINFO_FILENAME))));
    }
}
