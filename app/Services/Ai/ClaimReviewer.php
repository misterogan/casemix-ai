<?php

namespace App\Services\Ai;

use App\Enums\Severity;
use App\Models\AiProvider as ProviderConfig;
use App\Models\Claim;
use App\Services\Documents\DocumentReader;
use App\Support\Eklaim;
use App\Support\IcdGuard;

/**
 * Menyusun permintaan pemeriksaan berkas ke AI dan merapikan hasilnya.
 * AI hanya memberi temuan dan saran; tidak memberi kode ICD.
 */
class ClaimReviewer
{
    public function __construct(
        private AiManager $ai,
        private DocumentReader $reader,
    ) {}

    /**
     * @param  array  $ruleFindings  hasil ConsistencyChecker, ikut dikirim sebagai konteks
     * @return array{summary: string, findings: array, suggestions: array, response: AiResponse}
     */
    public function review(Claim $claim, ProviderConfig $config, array $ruleFindings): array
    {
        $request = new AiRequest($config->model_standard, $this->systemPrompt(), $config->max_tokens);
        $request->text($this->claimContext($claim, $ruleFindings));

        $imageBudget = (int) config('casemix.ai.max_images_total');
        $maxChars = (int) config('casemix.ai.max_text_chars_per_doc');

        foreach ($claim->documents as $i => $doc) {
            $no = $i + 1;
            $header = "\n=== DOKUMEN $no: {$doc->type->label()} — file \"{$doc->original_name}\" ===";

            $content = $this->reader->read($doc->absolutePath(), $doc->mime);
            $doc->forceFill([
                'page_count' => $content['pages'],
                'has_text_layer' => $content['has_text'],
                'extracted_text' => $content['text'] ? mb_substr($content['text'], 0, 200000) : null,
            ])->saveQuietly();

            if ($content['text']) {
                $full = mb_strlen($content['text']);
                $text = mb_substr($content['text'], 0, $maxChars);
                $note = $full > $maxChars
                    ? "\n(TERPOTONG: hanya $maxChars dari $full karakter dikirim. Jangan menyimpulkan sesuatu tidak ada di dokumen ini.)"
                    : '';
                $request->text("$header$note\n$text");

                continue;
            }

            if ($content['images'] === []) {
                $request->text("$header\n(Isi dokumen tidak bisa dibaca.)");

                continue;
            }

            $taken = array_slice($content['images'], 0, max(0, $imageBudget));
            $imageBudget -= count($taken);
            $skipped = count($content['images']) - count($taken);

            $request->text("$header\n(Hasil scan, " . count($taken) . ' halaman terlampir sebagai gambar'
                . ($skipped > 0 ? ", $skipped halaman tidak dikirim karena batas gambar" : '') . ')');
            foreach ($taken as $img) {
                $request->image($img['media_type'], $img['data']);
            }
        }

        $request->text($this->outputInstruction());

        $response = $this->ai->for($config)->generate($request);
        $data = $response->json();

        return [
            'summary' => trim((string) ($data['ringkasan'] ?? '')),
            'findings' => $this->cleanFindings($data['temuan'] ?? []),
            'suggestions' => $this->cleanSuggestions($data['saran_diagnosis'] ?? []),
            'response' => $response,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Anda adalah asisten pemeriksa berkas klaim JKN untuk tim casemix rumah sakit di Indonesia.
Tugas Anda membantu petugas menemukan ketidaklengkapan dan ketidaksesuaian SEBELUM klaim dientri dan difinalkan di E-Klaim.
Keputusan dan tanggung jawab akhir ada pada petugas casemix dan DPJP.

Aturan wajib:
1. JANGAN memberi kode ICD-10, ICD-9-CM, atau kode INA-CBG/iDRG dalam bentuk apa pun. Tulis diagnosis dan tindakan dalam kata-kata.
2. Hanya laporkan hal yang didukung isi dokumen. Sebutkan dokumen dan bagian yang menjadi dasar. Jika tidak terbaca, katakan tidak terbaca; jangan menebak.
3. Saran diagnosis tambahan adalah bahan konfirmasi ke DPJP, bukan diagnosis. Sertakan bukti spesifik (nilai lab, nama obat, hasil radiologi).
4. Tujuannya klaim yang benar dan lengkap sesuai rekam medis, bukan klaim yang "lolos" atau bernilai lebih tinggi. Jangan menyarankan penambahan diagnosis tanpa bukti klinis.
5. Bila dokumen ditandai TERPOTONG atau sebagian halaman tidak dikirim, jangan melaporkan sesuatu "tidak ada" hanya karena tidak terlihat. Tulis bahwa bagian itu perlu dicek manual.
6. Gunakan bahasa Indonesia yang ringkas dan jelas untuk petugas casemix.
TXT;
    }

    private function claimContext(Claim $c, array $ruleFindings): string
    {
        $opt = fn (array $map, $v) => $v === null ? '-' : ($map[$v] ?? $v);
        $tarif = collect(Eklaim::TARIF)
            ->map(fn ($label, $k) => (float) ($c->tarif[$k] ?? 0) > 0 ? "  - $label: " . Eklaim::rupiah($c->tarif[$k]) : null)
            ->filter()->implode("\n");

        $rules = collect($ruleFindings)->map(fn ($f) => "  - [{$f['tingkat']}] {$f['judul']}: {$f['uraian']}")->implode("\n");

        $lines = [
            'DATA KLAIM YANG DIISI PETUGAS (akan dientri ke E-Klaim):',
            "- Nama pasien: {$c->nama_pasien}",
            "- No. RM: {$c->no_rm}",
            '- No. SEP: ' . ($c->no_sep ?: '-'),
            // No. peserta BPJS sengaja tidak dikirim: tidak diperlukan untuk pemeriksaan (minimisasi data, UU PDP).
            '- Tanggal lahir: ' . ($c->tanggal_lahir?->format('d-m-Y') ?? '-') . ' | Jenis kelamin: ' . ($c->jenis_kelamin ?: '-'),
            '- Jenis rawat: ' . $opt(Eklaim::JENIS_RAWAT, $c->jenis_rawat)
                . ' | Hak kelas: ' . $opt(Eklaim::KELAS, $c->kelas_hak)
                . ' | Kelas rawat: ' . $opt(Eklaim::KELAS_RAWAT, $c->kelas_rawat)
                . ' | Naik kelas: ' . ($c->naik_kelas ? 'ya' : 'tidak'),
            '- Tanggal masuk: ' . $c->tanggal_masuk?->format('d-m-Y') . ' | Tanggal keluar: ' . ($c->tanggal_keluar?->format('d-m-Y') ?? '-') . ' | LOS: ' . ($c->los() ?? '-') . ' hari',
            '- Cara masuk: ' . $opt(Eklaim::CARA_MASUK, $c->cara_masuk) . ' | Cara pulang: ' . $opt(Eklaim::CARA_PULANG, $c->cara_pulang),
            '- Berat lahir: ' . ($c->berat_lahir ? "{$c->berat_lahir} gram" : '-')
                . ' | Rawat intensif: ' . ($c->rawat_intensif_hari ?? 0) . ' hari'
                . ' | Ventilator: ' . ($c->ventilator_jam ?? 0) . ' jam',
            '- Pasien TB: ' . ($c->pasien_tb ? 'ya' : 'tidak') . ' | Tekanan darah: ' . ($c->tekanan_darah ?: '-'),
            "- DPJP: {$c->dpjp}",
            '- Diagnosis utama: ' . ($c->diagnosis_utama ?: '-'),
            '- Diagnosis sekunder: ' . ($c->diagnosis_sekunder ?: '-'),
            '- Tindakan: ' . ($c->tindakan ?: '-'),
            '- Komponen tarif (yang bernilai):',
            $tarif ?: '  (belum diisi)',
            '',
            'TEMUAN PEMERIKSAAN ATURAN OTOMATIS (sudah diketahui petugas, tidak perlu diulang kecuali ada tambahan dari dokumen):',
            $rules ?: '  (tidak ada)',
            '',
            'DOKUMEN TERLAMPIR:',
        ];

        return implode("\n", $lines);
    }

    private function outputInstruction(): string
    {
        return <<<'TXT'

=== YANG HARUS DIPERIKSA ===
1. Identitas (nama, No. RM, tanggal lahir) dan No. SEP sama di semua dokumen dan sama dengan data klaim.
2. Tanggal masuk/keluar di SEP, resume medis, dan rincian biaya konsisten.
3. Resume medis ditandatangani DPJP dan bertanggal; nama DPJP sesuai.
4. Bukti untuk setiap diagnosis (hasil lab, obat, radiologi, anamnesis/pemeriksaan).
5. Bukti klinis untuk kondisi yang BELUM tertulis sebagai diagnosis (contoh: insulin diberikan tetapi diabetes tidak tertulis; transfusi tetapi anemia tidak tertulis).
6. Tindakan di data klaim sesuai laporan operasi atau catatan tindakan.
7. Komponen biaya sesuai pelayanan yang tercatat (biaya tanpa bukti layanan, atau layanan tanpa biaya).
8. Field E-Klaim (cara masuk, cara pulang, kelas, berat lahir, rawat intensif) sesuai isi dokumen.

=== FORMAT JAWABAN ===
Jawab HANYA dengan satu objek JSON, tanpa teks lain dan tanpa ```:
{
  "ringkasan": "2-3 kalimat kondisi berkas secara umum",
  "temuan": [
    {
      "tingkat": "kritis | perhatian | sesuai",
      "kategori": "Identitas | Tanggal | Tanda tangan | Diagnosis | Tindakan | Biaya vs bukti | Field E-Klaim | Lainnya",
      "judul": "kalimat pendek",
      "uraian": "penjelasan dan tindakan yang disarankan untuk petugas",
      "dokumen": "nama dokumen yang menjadi dasar"
    }
  ],
  "saran_diagnosis": [
    {
      "kondisi": "nama kondisi dalam kata-kata, TANPA kode",
      "bukti": "bukti spesifik dari dokumen",
      "dokumen": "nama dokumen",
      "catatan": "apa yang perlu dikonfirmasi ke DPJP"
    }
  ]
}
"kritis" = harus diperbaiki sebelum klaim dikirim. "perhatian" = perlu dicek petugas. "sesuai" = hal penting yang sudah benar (maksimal 5).
TXT;
    }

    private function cleanFindings(array $items): array
    {
        return collect($items)->filter(fn ($f) => is_array($f) && ! empty($f['judul']))
            ->map(fn ($f) => [
                'tingkat' => Severity::tryFrom(mb_strtolower(trim((string) ($f['tingkat'] ?? ''))))?->value ?? Severity::Perhatian->value,
                'kategori' => (string) ($f['kategori'] ?? 'Lainnya'),
                'judul' => $this->stripCodes((string) $f['judul']),
                'uraian' => $this->stripCodes((string) ($f['uraian'] ?? '')),
                'dokumen' => (string) ($f['dokumen'] ?? ''),
            ])->values()->all();
    }

    private function cleanSuggestions(array $items): array
    {
        return collect($items)->filter(fn ($s) => is_array($s) && ! empty($s['kondisi']))
            ->map(fn ($s) => [
                'kondisi' => $this->stripCodes((string) $s['kondisi']),
                'bukti' => $this->stripCodes((string) ($s['bukti'] ?? '')),
                'dokumen' => (string) ($s['dokumen'] ?? ''),
                'catatan' => $this->stripCodes((string) ($s['catatan'] ?? '')),
            ])->values()->all();
    }

    private function stripCodes(string $text): string
    {
        return IcdGuard::strip($text);
    }
}
