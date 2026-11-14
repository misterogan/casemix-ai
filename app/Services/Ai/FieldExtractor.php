<?php

namespace App\Services\Ai;

use App\Enums\DocumentType;
use App\Models\AiProvider as ProviderConfig;
use App\Models\Claim;
use App\Services\Documents\DocumentReader;
use App\Support\ClaimFields;
use App\Support\Eklaim;

/**
 * Membaca SEP, resume medis, rincian biaya, dan laporan operasi untuk MENGUSULKAN isian data klaim,
 * supaya petugas tidak mengetik ulang data yang sudah ada di dokumen.
 * Hasilnya hanya usulan: tidak ada yang diterapkan tanpa konfirmasi petugas.
 */
class FieldExtractor
{
    /** Dokumen yang dibaca, sesuai urutan kepentingan. */
    public const SOURCE_TYPES = [DocumentType::Sep, DocumentType::ResumeMedis, DocumentType::RincianBiaya, DocumentType::LaporanOperasi];

    public function __construct(private AiManager $ai, private DocumentReader $reader) {}

    public static function sourceDocuments(Claim $claim)
    {
        return $claim->documents->filter(fn ($d) => in_array($d->type, self::SOURCE_TYPES, true))
            ->sortBy(fn ($d) => array_search($d->type, self::SOURCE_TYPES, true))->values();
    }

    /** @return array{suggestions: array<string, array{nilai: mixed, dokumen: string, keyakinan: string}>, response: AiResponse} */
    public function extract(Claim $claim, ProviderConfig $config): array
    {
        $request = new AiRequest($config->model_standard, $this->systemPrompt(), $config->max_tokens);
        $request->text($this->instruction());

        $imageBudget = (int) config('casemix.ai.max_images_total');
        $maxChars = (int) config('casemix.ai.max_text_chars_per_doc');

        foreach (self::sourceDocuments($claim) as $i => $doc) {
            $header = "\n=== DOKUMEN " . ($i + 1) . ": {$doc->type->label()} ===";
            $content = $this->reader->read($doc->absolutePath(), $doc->mime);

            if ($content['text']) {
                $request->text("$header\n" . mb_substr($content['text'], 0, $maxChars));

                continue;
            }
            $taken = array_slice($content['images'], 0, max(0, $imageBudget));
            $imageBudget -= count($taken);
            $request->text("$header\n" . ($taken ? '(Hasil scan, ' . count($taken) . ' halaman terlampir sebagai gambar)' : '(Isi dokumen tidak bisa dibaca.)'));
            foreach ($taken as $img) {
                $request->image($img['media_type'], $img['data']);
            }
        }

        $response = $this->ai->for($config)->generate($request);

        return ['suggestions' => $this->clean($response->json()['isian'] ?? []), 'response' => $response];
    }

    /**
     * Hanya field yang dikenal dan nilainya valid yang disimpan. Nilai ganda untuk field yang sama:
     * yang keyakinannya lebih tinggi dipakai.
     */
    public function clean(array $items): array
    {
        $rank = ['tinggi' => 3, 'sedang' => 2, 'rendah' => 1];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['field'] ?? null)) {
                continue;
            }
            $field = trim($item['field']);
            $value = ClaimFields::normalize($field, $item['nilai'] ?? null);
            if ($value === null) {
                continue;
            }
            $keyakinan = in_array($item['keyakinan'] ?? '', ['tinggi', 'sedang', 'rendah'], true) ? $item['keyakinan'] : 'rendah';
            if (isset($out[$field]) && $rank[$out[$field]['keyakinan']] >= $rank[$keyakinan]) {
                continue;
            }
            $out[$field] = ['nilai' => $value, 'dokumen' => mb_substr((string) ($item['dokumen'] ?? ''), 0, 100), 'keyakinan' => $keyakinan];
        }

        return $out;
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Anda membantu petugas casemix rumah sakit di Indonesia menyalin data dari dokumen klaim JKN ke formulir.
Aturan wajib:
1. Salin hanya yang TERTULIS di dokumen. Jangan menebak, menghitung, atau menyimpulkan. Bila tidak tertulis jelas, jangan isi field itu.
2. JANGAN menulis kode ICD-10, ICD-9-CM, atau INA-CBG. Diagnosis dan tindakan ditulis dalam kata-kata persis seperti di resume medis.
3. Jangan menyalin nomor kartu peserta BPJS.
4. Bila dokumen saling bertentangan, ambil dari dokumen yang paling berwenang (identitas dan SEP dari SEP; diagnosis dan cara pulang dari resume medis; biaya dari rincian biaya) dan beri keyakinan "rendah".
TXT;
    }

    private function instruction(): string
    {
        $opsi = fn (array $m) => implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($m), $m));
        $tarif = implode(', ', array_keys(Eklaim::TARIF));

        return <<<TXT
Isi field berikut bila tertulis di dokumen:
- nama_pasien, no_rm, no_sep (19 karakter), dpjp (nama dokter penanggung jawab)
- tanggal_lahir, tanggal_masuk, tanggal_keluar (format YYYY-MM-DD)
- jenis_kelamin: L atau P
- jenis_rawat: {$opsi(Eklaim::JENIS_RAWAT)}
- kelas_hak: {$opsi(Eklaim::KELAS)}
- kelas_rawat: {$opsi(Eklaim::KELAS_RAWAT)}
- cara_masuk: {$opsi(Eklaim::CARA_MASUK)}
- cara_pulang: {$opsi(Eklaim::CARA_PULANG)}
- berat_lahir (gram), rawat_intensif_hari, ventilator_jam: angka
- tekanan_darah: seperti 120/80
- diagnosis_utama, diagnosis_sekunder (satu per baris), tindakan (satu per baris): kata-kata dari resume medis, tanpa kode
- tarif.<komponen>: total rupiah per komponen dari rincian biaya, hanya bila rincian biaya jelas mengelompokkannya. Komponen: $tarif

Jawab HANYA dengan satu objek JSON, tanpa teks lain dan tanpa ```:
{"isian": [{"field": "nama field", "nilai": "nilai", "dokumen": "SEP | Resume medis | Rincian biaya | Laporan operasi", "keyakinan": "tinggi | sedang | rendah"}]}
Field yang tidak tertulis jelas jangan dimasukkan.
TXT;
    }
}
