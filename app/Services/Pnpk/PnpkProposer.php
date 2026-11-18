<?php

namespace App\Services\Pnpk;

use App\Models\RegulasiButir;
use App\Models\RegulasiIcd;
use App\Services\Ai\AiRequest;
use App\Support\IcdGuard;

/**
 * Permintaan AI untuk mengusulkan ICD & butir acuan regulasi dari potongan halaman, plus validasi skema ketat.
 * Arahan berbeda per jenis dokumen (PNPK, Permenkes 26/2021, BA kesepakatan); aturan bukti sama untuk semua.
 */
class PnpkProposer
{
    public const CHUNK = 15;

    public const MAX_KATA_KUTIPAN = 40;

    private const ARAHAN = [
        'kmk_pnpk' => 'Dokumen ini adalah Pedoman Nasional Pelayanan Kedokteran (PNPK). Susun butir kriteria diagnosis, penunjang, indikasi rawat inap, tata laksana, kriteria pulang, komplikasi, dan lama rawat.',
        'permenkes' => 'Dokumen ini adalah Peraturan Menteri Kesehatan (mis. Permenkes 26/2021 tentang pedoman INA-CBG). Susun butir KAIDAH KODING: diagnosis utama, diagnosis sekunder, kode kombinasi, prosedur, episode/readmisi, dan kaidah MB1–MB5 bila tertulis. "pasal" diisi pasal atau bagian lampiran persis seperti tertulis (mis. "Lampiran Bab II huruf B").',
        'ba_kesepakatan' => 'Dokumen ini adalah Berita Acara kesepakatan Kemenkes–BPJS. Untuk TIAP KASUS: kondisi/diagnosis, kriteria klinis yang disepakati, kode yang disepakati, dan berkas/penunjang wajib. "pasal" diisi penanda kasus persis seperti tertulis (mis. "Kasus 3").',
    ];

    /** @param array<int, string> $pages potongan halaman => teks */
    public function request(string $model, int $maxTokens, string $judul, array $pages, string $jenisDokumen = 'kmk_pnpk'): AiRequest
    {
        $jenis = implode('|', RegulasiButir::jenisUntuk($jenisDokumen));
        $arahan = self::ARAHAN[$jenisDokumen] ?? 'Susun butir aturan yang relevan untuk koding dan verifikasi klaim.';
        $isi = collect($pages)->map(fn ($t, $h) => "=== HALAMAN $h ===\n$t")->implode("\n\n");
        $system = <<<TXT
Anda membantu menyusun ringkasan dokumen regulasi kesehatan resmi dari teks yang diberikan. $arahan
Aturan wajib:
1. Gunakan HANYA teks dokumen yang diberikan. Jangan menambah pengetahuan dari luar dokumen.
2. "kutipan" disalin PERSIS dari teks yang diberikan (maks 40 kata), tanpa mengubah kata. Bila tidak ada kutipan yang mendukung, jangan buat butirnya.
3. "halaman" adalah nomor halaman tempat kutipan berada. "pasal" hanya bila tertulis di teks; bila tidak, null.
4. "icd", "cakupan_icd", "cakupan_icd9", "cakupan_inacbg" hanya kode yang tertulis di teks dokumen. Bila tidak ada, kosongkan.
5. Bila tidak ada isi yang relevan, kembalikan daftar kosong.
TXT;

        return (new AiRequest($model, $system, $maxTokens, 0.0))->text(
            "Dokumen: \"$judul\".\n\n$isi\n\n"
            . 'Jawab HANYA satu objek JSON tanpa ```: {"icd":["J18"],"butir":[{"jenis":"' . $jenis . '","ringkasan":"maks 300 karakter","kutipan":"salinan persis maks 40 kata","halaman":1,"pasal":"bila tertulis, atau null","cakupan_icd":["prefix ICD-10 tertulis"],"cakupan_icd9":[],"cakupan_inacbg":[],"level_bukti":"bila tertulis, atau null"}]}'
        );
    }

    /**
     * Validasi skema ketat: item yang tidak valid dibuang. Pasal & kode cakupan yang tidak tertulis di teks potongan dibuang.
     *
     * @param array<int> $halamanValid
     * @param array<int, string> $pages teks potongan (untuk memeriksa pasal & kode)
     * @return array{icd: string[], butir: array<int, array<string, mixed>>}
     */
    public function parse(array $json, array $halamanValid, array $pages = [], string $jenisDokumen = 'kmk_pnpk'): array
    {
        $boleh = RegulasiButir::jenisUntuk($jenisDokumen);
        $teks = mb_strtolower(preg_replace('/\s+/u', ' ', implode(' ', $pages)));
        $icd = collect(is_array($json['icd'] ?? null) ? $json['icd'] : [])
            ->filter(fn ($k) => is_string($k) && preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/', RegulasiIcd::normalize($k)))
            ->map(fn ($k) => RegulasiIcd::normalize($k))->unique()->values()->all();

        $butir = collect(is_array($json['butir'] ?? null) ? $json['butir'] : [])->filter(function ($b) use ($halamanValid, $boleh) {
            return is_array($b)
                && is_string($b['jenis'] ?? null) && in_array($b['jenis'], $boleh, true)
                && is_string($b['ringkasan'] ?? null) && trim($b['ringkasan']) !== '' && mb_strlen($b['ringkasan']) <= 300
                && is_string($b['kutipan'] ?? null) && trim($b['kutipan']) !== ''
                && count(preg_split('/\s+/u', trim($b['kutipan']))) <= self::MAX_KATA_KUTIPAN
                && is_int($b['halaman'] ?? null) && in_array($b['halaman'], $halamanValid, true)
                && (! isset($b['level_bukti']) || is_string($b['level_bukti']));
        })->map(fn ($b) => [
            'jenis' => $b['jenis'],
            'ringkasan' => mb_substr(IcdGuard::strip(trim($b['ringkasan'])), 0, 300),
            'kutipan' => trim($b['kutipan']),
            'halaman' => $b['halaman'],
            'pasal' => self::pasal($b['pasal'] ?? null, $teks),
            'cakupan_icd' => self::kode($b['cakupan_icd'] ?? null, '/^[A-Z][0-9]{2}(\.?[0-9A-Z]{1,4})?$/', $teks),
            'cakupan_icd9' => self::kode($b['cakupan_icd9'] ?? null, '/^[0-9]{2}(\.?[0-9]{1,2})?$/', $teks),
            'cakupan_inacbg' => self::kode($b['cakupan_inacbg'] ?? null, '/^[A-Z](-[0-9]{1,2}(-[0-9]{1,2}(-[0IVX]+)?)?)?$/', $teks),
            'level_bukti' => isset($b['level_bukti']) && trim($b['level_bukti']) !== '' ? mb_substr(trim($b['level_bukti']), 0, 40) : null,
        ])->values()->all();

        return ['icd' => $icd, 'butir' => $butir];
    }

    /** Pasal/kasus dipertahankan hanya bila tiap katanya tertulis di teks (tanpa teks pembanding: dipertahankan). */
    private static function pasal(mixed $pasal, string $teks): ?string
    {
        if (! is_string($pasal) || trim($pasal) === '') {
            return null;
        }
        $pasal = mb_substr(trim($pasal), 0, 150);
        if ($teks === '') {
            return $pasal;
        }
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($pasal), -1, PREG_SPLIT_NO_EMPTY) as $kata) {
            if (! str_contains($teks, $kata)) {
                return null;
            }
        }

        return $pasal;
    }

    /** @return string[]|null kode yang lolos pola dan tertulis di teks */
    private static function kode(mixed $daftar, string $pola, string $teks): ?array
    {
        $hasil = collect(is_array($daftar) ? $daftar : [])->filter(fn ($k) => is_string($k))
            ->map(fn ($k) => strtoupper(trim($k)))
            ->filter(fn ($k) => preg_match($pola, $k) && ($teks === '' || str_contains($teks, mb_strtolower($k))
                || str_contains(str_replace('.', '', $teks), mb_strtolower(str_replace('.', '', $k)))))
            ->unique()->values()->all();

        return $hasil ?: null;
    }
}
