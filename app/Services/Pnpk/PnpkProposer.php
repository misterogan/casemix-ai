<?php

namespace App\Services\Pnpk;

use App\Models\PnpkButir;
use App\Models\PnpkIcd;
use App\Services\Ai\AiRequest;
use App\Support\IcdGuard;

/** Permintaan AI untuk mengusulkan ICD & butir PNPK dari potongan halaman, plus validasi skema ketat. */
class PnpkProposer
{
    public const CHUNK = 15;

    public const MAX_KATA_KUTIPAN = 40;

    /** @param array<int, string> $pages potongan halaman => teks */
    public function request(string $model, int $maxTokens, string $judul, array $pages): AiRequest
    {
        $jenis = implode('|', array_keys(PnpkButir::JENIS));
        $isi = collect($pages)->map(fn ($t, $h) => "=== HALAMAN $h ===\n$t")->implode("\n\n");
        $system = <<<'TXT'
Anda membantu menyusun ringkasan Pedoman Nasional Pelayanan Kedokteran (PNPK) dari teks dokumen resmi yang diberikan.
Aturan wajib:
1. Gunakan HANYA teks dokumen yang diberikan. Jangan menambah pengetahuan dari luar dokumen.
2. "kutipan" disalin PERSIS dari teks yang diberikan (maks 40 kata), tanpa mengubah kata. Bila tidak ada kutipan yang mendukung, jangan buat butirnya.
3. "halaman" adalah nomor halaman tempat kutipan berada.
4. "icd" hanya kode ICD-10 yang tertulis di teks dokumen. Bila tidak ada, kosongkan.
5. Bila tidak ada isi yang relevan, kembalikan daftar kosong.
TXT;

        return (new AiRequest($model, $system, $maxTokens, 0.0))->text(
            "Dokumen: \"$judul\".\n\n$isi\n\n"
            . 'Jawab HANYA satu objek JSON tanpa ```: {"icd":["J18"],"butir":[{"jenis":"' . $jenis . '","ringkasan":"maks 300 karakter","kutipan":"salinan persis maks 40 kata","halaman":1,"level_bukti":"bila tertulis, atau null"}]}'
        );
    }

    /**
     * Validasi skema ketat: item yang tidak valid dibuang.
     *
     * @param array<int> $halamanValid
     * @return array{icd: string[], butir: array<int, array{jenis: string, ringkasan: string, kutipan: string, halaman: int, level_bukti: ?string}>}
     */
    public function parse(array $json, array $halamanValid): array
    {
        $icd = collect(is_array($json['icd'] ?? null) ? $json['icd'] : [])
            ->filter(fn ($k) => is_string($k) && preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/', PnpkIcd::normalize($k)))
            ->map(fn ($k) => PnpkIcd::normalize($k))->unique()->values()->all();

        $butir = collect(is_array($json['butir'] ?? null) ? $json['butir'] : [])->filter(function ($b) use ($halamanValid) {
            return is_array($b)
                && is_string($b['jenis'] ?? null) && isset(PnpkButir::JENIS[$b['jenis']])
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
            'level_bukti' => isset($b['level_bukti']) && trim($b['level_bukti']) !== '' ? mb_substr(trim($b['level_bukti']), 0, 40) : null,
        ])->values()->all();

        return ['icd' => $icd, 'butir' => $butir];
    }
}
