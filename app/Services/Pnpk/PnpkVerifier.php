<?php

namespace App\Services\Pnpk;

use App\Models\RegulasiIcd;

/**
 * Verifikasi tanpa AI: kutipan harus ada di teks dokumen (seluruh halaman, toleran tanda air & baris terpotong);
 * kode ICD harus ada di master.
 */
class PnpkVerifier
{
    public const TOLAK_KUTIPAN = 'Kutipan tidak ditemukan di dokumen';

    public const TOLAK_ICD = 'Kode tidak ada di master ICD-10';

    /** Maks token sisipan di antara dua token kutipan. */
    public const MAKS_SISIPAN = 3;

    public const MIN_KEMIRIPAN = 0.92;

    /** Ambang porsi halaman agar token dianggap sisa tanda air. */
    public const AMBANG_TANDA_AIR = 0.6;

    /** Kata tanda air umum di PDF Kemenkes. */
    private const KATA_TANDA_AIR = ['kementerian', 'kesehatan', 'republik', 'indonesia', 'kemenkes'];

    /** Kata umum yang tidak pernah dianggap tanda air walau sering muncul. */
    private const KATA_UMUM = ['yang', 'dan', 'di', 'dari', 'pada', 'untuk', 'dengan', 'atau', 'ini', 'itu', 'dalam', 'adalah',
        'tidak', 'akan', 'dapat', 'oleh', 'sebagai', 'juga', 'bila', 'jika', 'pasien', 'the', 'of', 'and', 'in', 'to', 'a'];

    /** @var array{key: string, tokens: string[], page: int[], wm: array<string, true>}|null */
    private ?array $korpus = null;

    /** Huruf kecil, tanpa diakritik, kata terpotong tanda hubung disatukan, non-huruf/angka → spasi, spasi dirapatkan. */
    public static function normalize(string $s): string
    {
        $s = str_replace("\u{00AD}", '', $s);
        $s = preg_replace('/(\p{L})[-\x{2010}\x{2011}]\s*[\r\n]+\s*(\p{L})/u', '$1$2', $s);
        $s = mb_strtolower($s);
        if (class_exists(\Normalizer::class)) {
            $s = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
        }
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** @return string[] */
    public static function tokens(string $s): array
    {
        $n = self::normalize($s);

        return $n === '' ? [] : explode(' ', $n);
    }

    /** @param array<int, string> $pages nomor halaman => teks */
    public function kutipanCocok(string $kutipan, int $halaman, array $pages): bool
    {
        return $this->cariHalaman($kutipan, $halaman, $pages) !== null;
    }

    /**
     * Cari kutipan di seluruh dokumen. Mengembalikan halaman PDF tempat kutipan ditemukan
     * (terdekat ke label halaman dari AI bila ditemukan di beberapa tempat), atau null.
     *
     * @param array<int, string> $pages nomor halaman => teks
     */
    public function cariHalaman(string $kutipan, int $halaman, array $pages): ?int
    {
        $k = $this->siapkan($pages);
        $q = self::tokens($kutipan);
        $qBersih = array_values(array_filter($q, fn ($t) => ! isset($k['wm'][$t])));
        if (count($qBersih) >= 3) {
            $q = $qBersih;
            $t = $k['bersih'];
            $pg = $k['pageBersih'];
        } else {
            $t = $k['tokens'];
            $pg = $k['page'];
        }
        if ($q === [] || $t === []) {
            return null;
        }

        $mulai = $this->berurutan($q, $t);
        if ($mulai === []) {
            $mulai = $this->mirip($q, $t);
        }
        if ($mulai === []) {
            return null;
        }
        $halamanDitemukan = array_unique(array_map(fn ($i) => $pg[$i], $mulai));
        usort($halamanDitemukan, fn ($a, $b) => [abs($a - $halaman), $a] <=> [abs($b - $halaman), $b]);

        return $halamanDitemukan[0];
    }

    public function icdValid(string $kode): bool
    {
        return RegulasiIcd::adaDiMaster($kode);
    }

    /** @return array<string, true> token sisa tanda air dokumen */
    public function tandaAir(array $pages): array
    {
        return $this->siapkan($pages)['wm'];
    }

    // ------------------------------------------------------------------ internal

    /** Tokenisasi seluruh dokumen sekali (dicache per isi dokumen). */
    private function siapkan(array $pages): array
    {
        ksort($pages);
        $key = md5(serialize($pages));
        if ($this->korpus && $this->korpus['key'] === $key) {
            return $this->korpus;
        }
        $tokens = $page = [];
        $df = [];
        foreach ($pages as $h => $teks) {
            $tk = self::tokens((string) $teks);
            foreach (array_unique($tk) as $x) {
                $df[$x] = ($df[$x] ?? 0) + 1;
            }
            foreach ($tk as $x) {
                $tokens[] = $x;
                $page[] = (int) $h;
            }
        }

        // Sisa tanda air: muncul di ≥60% halaman, bukan kata umum; kata tanda air Kemenkes atau fragmen 1–3 huruf.
        $wm = [];
        $n = count($pages);
        if ($n >= 3) {
            foreach ($df as $x => $c) {
                $x = (string) $x;
                if ($c / $n >= self::AMBANG_TANDA_AIR && ! in_array($x, self::KATA_UMUM, true)
                    && (in_array($x, self::KATA_TANDA_AIR, true) || preg_match('/^\p{L}{1,3}$/u', $x))) {
                    $wm[$x] = true;
                }
            }
        }
        $bersih = $pageBersih = [];
        foreach ($tokens as $i => $x) {
            if (! isset($wm[$x])) {
                $bersih[] = $x;
                $pageBersih[] = $page[$i];
            }
        }

        return $this->korpus = ['key' => $key, 'tokens' => $tokens, 'page' => $page, 'wm' => $wm, 'bersih' => $bersih, 'pageBersih' => $pageBersih];
    }

    /**
     * Semua token kutipan muncul berurutan dengan ≤ MAKS_SISIPAN token sisipan di antaranya.
     *
     * @return int[] indeks token awal setiap kecocokan
     */
    private function berurutan(array $q, array $t): array
    {
        $hasil = [];
        $n = count($t);
        $m = count($q);
        for ($i = 0; $i < $n; $i++) {
            if ($t[$i] !== $q[0]) {
                continue;
            }
            $cur = [$i => true];
            for ($j = 1; $j < $m && $cur; $j++) {
                $next = [];
                foreach (array_keys($cur) as $c) {
                    for ($p = $c + 1; $p <= $c + 1 + self::MAKS_SISIPAN && $p < $n; $p++) {
                        if ($t[$p] === $q[$j]) {
                            $next[$p] = true;
                        }
                    }
                }
                $cur = $next;
            }
            if ($cur) {
                $hasil[] = $i;
            }
        }

        return $hasil;
    }

    /**
     * Kemiripan rasio LCS token ≥ MIN_KEMIRIPAN pada jendela teks (prafilter jumlah token sama).
     *
     * @return int[] indeks token awal jendela yang cocok
     */
    private function mirip(array $q, array $t): array
    {
        $m = count($q);
        $perlu = (int) ceil(self::MIN_KEMIRIPAN * $m);
        $w = $m + (int) ceil($m / 2);
        $n = count($t);
        $butuh = array_count_values($q);
        $hasil = [];
        for ($i = 0; $i < $n; $i++) {
            if (! isset($butuh[$t[$i]])) {
                continue;
            }
            $jendela = array_slice($t, $i, $w);
            $ada = 0;
            foreach (array_count_values($jendela) as $x => $c) {
                $ada += min($c, $butuh[$x] ?? 0);
            }
            if ($ada >= $perlu && self::lcs($q, $jendela) >= $perlu) {
                $hasil[] = $i;
                $i += $w - 1;
            }
        }

        return $hasil;
    }

    private static function lcs(array $a, array $b): int
    {
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $x) {
            $cur = [0];
            foreach ($b as $j => $y) {
                $cur[$j + 1] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $cur[$j]);
            }
            $prev = $cur;
        }

        return end($prev);
    }
}
