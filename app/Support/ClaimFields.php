<?php

namespace App\Support;

use App\Models\Claim;

/**
 * Daftar field klaim yang boleh diusulkan dari dokumen, beserta cara memvalidasi,
 * membandingkan, menampilkan, dan menerapkannya. Tarif memakai kunci "tarif.<komponen>".
 *
 * No. peserta, ADL, naik kelas, eksekutif, dan status TB sengaja tidak diusulkan:
 * tidak diperlukan untuk pemeriksaan (minimisasi data) atau jarang tertulis jelas di dokumen.
 */
class ClaimFields
{
    /** field => [label, tipe, pilihan] */
    public static function spec(): array
    {
        $spec = [
            'nama_pasien' => ['Nama pasien', 'string', null],
            'no_rm' => ['No. RM', 'rm', null],
            'no_sep' => ['No. SEP', 'sep', null],
            'tanggal_lahir' => ['Tanggal lahir', 'date', null],
            'jenis_kelamin' => ['Jenis kelamin', 'enum', ['L' => 'Laki-laki', 'P' => 'Perempuan']],
            'jenis_rawat' => ['Jenis rawat', 'enum', Eklaim::JENIS_RAWAT],
            'kelas_hak' => ['Hak kelas', 'enum', Eklaim::KELAS],
            'kelas_rawat' => ['Kelas rawat', 'enum', Eklaim::KELAS_RAWAT],
            'tanggal_masuk' => ['Tanggal masuk', 'date', null],
            'tanggal_keluar' => ['Tanggal keluar', 'date', null],
            'cara_masuk' => ['Cara masuk', 'enum', Eklaim::CARA_MASUK],
            'cara_pulang' => ['Cara pulang', 'enum', Eklaim::CARA_PULANG],
            'dpjp' => ['DPJP', 'string', null],
            'berat_lahir' => ['Berat lahir (gram)', 'int', null],
            'rawat_intensif_hari' => ['Rawat intensif (hari)', 'int', null],
            'ventilator_jam' => ['Ventilator (jam)', 'int', null],
            'tekanan_darah' => ['Tekanan darah', 'bp', null],
            'diagnosis_utama' => ['Diagnosis utama', 'text', null],
            'diagnosis_sekunder' => ['Diagnosis sekunder', 'text', null],
            'tindakan' => ['Tindakan', 'text', null],
        ];
        foreach (Eklaim::TARIF as $k => $l) {
            $spec["tarif.$k"] = ["Tarif: $l", 'money', null];
        }

        return $spec;
    }

    public static function has(string $field): bool
    {
        return array_key_exists($field, self::spec());
    }

    public static function label(string $field): string
    {
        return self::spec()[$field][0] ?? $field;
    }

    /** Nilai usulan yang sudah divalidasi, atau null bila tidak valid. */
    public static function normalize(string $field, mixed $raw): mixed
    {
        if (! self::has($field) || $raw === null || (is_string($raw) && in_array(mb_strtolower(trim($raw)), ['', 'null', '-', 'tidak ada', 'tidak terbaca'], true))) {
            return null;
        }
        [, $type, $options] = self::spec()[$field];

        switch ($type) {
            case 'string':
                $v = trim(preg_replace('/\s+/', ' ', (string) $raw));

                return $v === '' ? null : mb_substr($v, 0, 150);
            case 'rm':
                $v = trim((string) $raw);

                return preg_match('/^\d{3,20}$/', $v) ? $v : null;
            case 'sep':
                $v = strtoupper(preg_replace('/\s+/', '', (string) $raw));

                return preg_match('/^[0-9A-Z]{19}$/', $v) ? $v : null;
            case 'date':
                return self::date((string) $raw);
            case 'enum':
                $v = (string) $raw;
                if (array_key_exists($v, $options)) {
                    return $v;
                }
                // Model kadang menjawab dengan label ("Rawat inap") alih-alih kunci
                $key = array_search(mb_strtolower(trim($v)), array_map('mb_strtolower', $options), true);

                return $key === false ? null : (string) $key;
            case 'int':
                $v = is_numeric($raw) ? (int) $raw : (int) preg_replace('/\D/', '', (string) $raw);

                return $v > 0 && $v < 100000 ? $v : null;
            case 'money':
                $v = is_int($raw) || is_float($raw) ? (int) round($raw) : (int) preg_replace('/\D/', '', preg_replace('/[,.]\d{1,2}$/', '', (string) $raw));

                return $v > 0 && $v < 10_000_000_000 ? $v : null;
            case 'bp':
                return preg_match('/^(\d{2,3})\s*\/\s*(\d{2,3})$/', trim((string) $raw), $m) ? "{$m[1]}/{$m[2]}" : null;
            case 'text':
                $v = IcdGuard::strip(trim((string) (is_array($raw) ? implode("\n", $raw) : $raw)));

                return $v === '' ? null : mb_substr($v, 0, 4000);
        }

        return null;
    }

    /** Nilai field di berkas saat ini, dalam bentuk yang sama dengan hasil normalize(). */
    public static function current(Claim $claim, string $field): mixed
    {
        if (str_starts_with($field, 'tarif.')) {
            return (int) ($claim->tarif[substr($field, 6)] ?? 0);
        }
        $v = $claim->{$field};
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }

        return $v === null ? null : (is_bool($v) ? $v : (is_int($v) ? $v : (string) $v));
    }

    public static function same(mixed $a, mixed $b): bool
    {
        $norm = fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v)));

        return $norm($a) === $norm($b) || (in_array($a, [null, '', 0, '0'], true) && in_array($b, [null, '', 0, '0'], true));
    }

    /** Teks yang mudah dibaca petugas. */
    public static function display(string $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === 0) {
            return '—';
        }
        [, $type, $options] = self::spec()[$field] ?? [null, 'string', null];

        return match ($type) {
            'date' => date('d/m/Y', strtotime((string) $value)),
            'enum' => $options[$value] ?? (string) $value,
            'money' => Eklaim::rupiah($value),
            default => (string) $value,
        };
    }

    /** Pasang nilai ke model (belum disimpan). */
    public static function apply(Claim $claim, string $field, mixed $value): void
    {
        if (str_starts_with($field, 'tarif.')) {
            $tarif = $claim->tarif ?? [];
            $tarif[substr($field, 6)] = (int) $value;
            $claim->tarif = $tarif;

            return;
        }
        $claim->{$field} = $value;
    }

    private static function date(string $raw): ?string
    {
        $raw = trim($raw);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d', 'd.m.Y', 'Y-m-d H:i', 'Y-m-d H:i:s', 'd-m-Y H:i'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($d && $d->format($fmt) === $raw) {
                $y = (int) $d->format('Y');

                return $y >= 1900 && $y <= (int) date('Y') + 1 ? $d->format('Y-m-d') : null;
            }
        }

        return null;
    }
}
