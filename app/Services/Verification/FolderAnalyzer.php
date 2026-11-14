<?php

namespace App\Services\Verification;

use App\Enums\DocumentType;
use App\Models\AiProvider as ProviderConfig;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\FtpFolder;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Documents\DocumentReader;
use App\Services\Import\DocumentGuesser;
use App\Services\Import\SimrsFtp;
use App\Services\Rules\ConsistencyChecker;
use App\Support\ClaimFields;
use App\Support\Eklaim;
use App\Support\IcdGuard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Membaca semua dokumen dalam satu folder FTP dan menghasilkan rekomendasi: apa yang kurang atau tidak cocok,
 * di dokumen dan halaman mana. Tidak ada yang diisi petugas dan tidak ada salinan yang disimpan:
 * file diunduh ke folder sementara dan dihapus setelah dibaca.
 */
class FolderAnalyzer
{
    /** Respons AI terakhir, agar biaya tetap tercatat walau hasilnya gagal diolah. */
    public ?\App\Services\Ai\AiResponse $lastResponse = null;

    public function __construct(private SimrsFtp $ftp, private DocumentReader $reader, private AiManager $ai, private ConsistencyChecker $rules) {}

    /** @return array{summary: string, findings: array, suggestions: array, extracted: array, response: AiResponse} */
    public function analyze(FtpFolder $folder, ProviderConfig $config): array
    {
        $disk = Storage::disk(config('casemix.storage_disk'));
        $tmp = 'tmp/analisa/' . Str::ulid();

        try {
            $docs = [];
            foreach ($folder->files as $i => $f) {
                $local = $this->ftp->download($f['path'], $tmp . '/' . $i . '.' . Str::lower(pathinfo($f['name'], PATHINFO_EXTENSION)));
                $mime = $disk->mimeType($local) ?: 'application/octet-stream';
                if (! in_array($mime, config('casemix.upload.content_types'), true)) {
                    continue; // bukan PDF/gambar: tidak dibaca
                }
                $docs[] = ['name' => $f['name'], 'type' => DocumentGuesser::type($f['name']), 'abs' => $disk->path($local), 'mime' => $mime];
            }

            $request = $this->buildRequest($folder, $docs, $config);
            $this->lastResponse = null;
            $response = $this->lastResponse = $this->ai->for($config)->generate($request);
            $json = $response->json();

            $extracted = $this->extracted($json['isian'] ?? []);
            $ruleFindings = $this->ruleFindings($folder, $docs, $extracted);
            $aiFindings = $this->findings($json['temuan'] ?? [], array_column($docs, 'name'));

            return [
                'summary' => IcdGuard::strip(trim((string) ($json['ringkasan'] ?? ''))),
                'findings' => array_merge($aiFindings, $ruleFindings),
                'suggestions' => $this->suggestions($json['kondisi'] ?? [], array_column($docs, 'name')),
                'extracted' => $extracted,
                'response' => $response,
            ];
        } finally {
            $disk->deleteDirectory($tmp);
        }
    }

    private function buildRequest(FtpFolder $folder, array $docs, ProviderConfig $config): AiRequest
    {
        $req = new AiRequest($config->model_standard, $this->systemPrompt(), $config->max_tokens);
        $req->text($this->instruction($folder, $docs));

        $imageBudget = (int) config('casemix.ai.max_images_total');
        $maxChars = (int) config('casemix.ai.max_text_chars_per_doc');
        $maxPages = (int) config('casemix.ai.max_pages_per_scan');

        foreach ($docs as $n => $d) {
            $head = "\n=== DOKUMEN " . ($n + 1) . ": \"{$d['name']}\" (perkiraan jenis: {$d['type']->label()}) ===";
            $pages = $d['mime'] === 'application/pdf' ? $this->reader->pdfPageTexts($d['abs']) : null;

            if ($pages) {
                $text = '';
                foreach ($pages as $p => $t) {
                    $text .= "\n[Halaman $p]\n$t";
                }
                $cut = mb_strlen($text) > $maxChars;
                $req->text($head . ($cut ? "\n(TERPOTONG: hanya sebagian halaman terkirim. Jangan menyimpulkan sesuatu tidak ada di dokumen ini.)" : '') . mb_substr($text, 0, $maxChars));

                continue;
            }

            $images = $d['mime'] === 'application/pdf'
                ? $this->reader->renderPdf($d['abs'], min($maxPages, max(0, $imageBudget)))
                : [$this->reader->read($d['abs'], $d['mime'])['images'][0] ?? null];
            $images = array_values(array_filter($images));
            $images = array_slice($images, 0, max(0, $imageBudget));
            $imageBudget -= count($images);

            $req->text($head . ($images ? "\n(Hasil scan. " . count($images) . ' halaman pertama terlampir sebagai gambar, berurutan dari halaman 1.)' : "\n(Tidak terkirim: batas gambar tercapai. Jangan menyimpulkan isinya.)"));
            foreach ($images as $p => $img) {
                $req->text("[Halaman " . ($p + 1) . ']');
                $req->image($img['media_type'], $img['data']);
            }
        }

        return $req;
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
Anda membantu Koordinator casemix rumah sakit di Indonesia memverifikasi kelengkapan dan konsistensi berkas klaim JKN (BPJS Kesehatan) SEBELUM diajukan lewat E-Klaim.
Aturan wajib:
1. Hanya memberi rekomendasi berdasarkan isi dokumen. Setiap temuan harus menyebut nama file dokumen dan nomor halamannya bila ada.
2. JANGAN menulis kode ICD-10, ICD-9-CM, atau INA-CBG. Koding dilakukan koder.
3. JANGAN menyarankan diagnosis atau tindakan yang tidak didukung bukti di dokumen, dan jangan menyarankan apa pun dengan tujuan menaikkan nilai klaim. Tujuannya klaim yang lengkap dan benar.
4. Kondisi yang punya bukti klinis tetapi belum tertulis sebagai diagnosis dilaporkan terpisah sebagai bahan konfirmasi ke DPJP, bukan sebagai perintah menambah diagnosis.
5. Bila dokumen terpotong atau tidak terkirim, jangan menyimpulkan sesuatu tidak ada; tulis bahwa bagian itu perlu dicek manual.
6. Jangan menyalin nomor kartu peserta BPJS.
7. Bahasa Indonesia yang ringkas dan jelas.
TXT;
    }

    private function instruction(FtpFolder $folder, array $docs): string
    {
        $opsi = fn (array $m) => implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($m), $m));
        $tarif = implode(', ', array_keys(Eklaim::TARIF));
        $list = implode("\n", array_map(fn ($d) => '- ' . $d['name'] . ' (' . $d['type']->label() . ')', $docs));

        return <<<TXT
Folder FTP: "{$folder->name}" (dari nama folder: RM {$folder->no_rm}, nama {$folder->nama_pasien}, SEP {$folder->no_sep}).
Dokumen dalam folder:
$list

Periksa:
1. Kelengkapan dokumen wajib klaim (SEP, resume medis, rincian biaya) dan dokumen pendukung yang seharusnya ada menurut isi berkas (mis. hasil radiologi bila ada biaya radiologi, laporan operasi bila ada tindakan bedah).
2. Identitas dan No. SEP sama di semua dokumen.
3. Tanggal masuk/keluar konsisten antar dokumen.
4. Resume medis lengkap: diagnosis, tindakan, kondisi pulang, tanda tangan dan nama DPJP.
5. Setiap diagnosis di resume punya bukti penunjang; setiap tindakan punya laporan atau hasilnya.
6. Biaya di rincian biaya sesuai layanan yang tercatat.
7. Kondisi dengan bukti klinis yang belum tertulis sebagai diagnosis (untuk konfirmasi DPJP).

Selain itu, salin data berikut bila tertulis jelas (dipakai aturan E-Klaim; jangan menebak): tanggal_masuk, tanggal_keluar (YYYY-MM-DD), jenis_rawat ({$opsi(Eklaim::JENIS_RAWAT)}), kelas_hak ({$opsi(Eklaim::KELAS)}), kelas_rawat ({$opsi(Eklaim::KELAS_RAWAT)}), cara_masuk ({$opsi(Eklaim::CARA_MASUK)}), cara_pulang ({$opsi(Eklaim::CARA_PULANG)}), tanggal_lahir, berat_lahir (gram), rawat_intensif_hari, ventilator_jam, dpjp, diagnosis_utama (kata-kata), tarif.<komponen> dari rincian biaya bila dikelompokkan jelas (komponen: $tarif).

Batas panjang jawaban (wajib):
- ringkasan maksimal 2 kalimat.
- temuan maksimal 12: semua kritis dan perhatian, plus paling banyak 3 sesuai.
- uraian maksimal 25 kata per temuan.
- kondisi maksimal 3, bukti maksimal 25 kata.
- isian hanya field yang tertulis jelas; tarif.<komponen> hanya yang nilainya > 0.
- JSON ringkas tanpa spasi atau baris baru tambahan.

Jawab HANYA satu objek JSON tanpa teks lain dan tanpa ```:
{"ringkasan": "2-3 kalimat kondisi berkas",
 "temuan": [{"tingkat": "kritis | perhatian | sesuai", "kategori": "Kelengkapan | Identitas | Tanggal | Diagnosis | Tindakan | Biaya | Lainnya", "judul": "singkat", "uraian": "apa yang kurang/tidak cocok dan apa yang perlu dilakukan", "dokumen": "nama file persis", "halaman": 1}],
 "kondisi": [{"kondisi": "...", "bukti": "...", "dokumen": "nama file persis", "halaman": 1}],
 "isian": [{"field": "...", "nilai": "...", "dokumen": "nama file", "keyakinan": "tinggi | sedang | rendah"}]}
Kritis = harus diselesaikan sebelum klaim diajukan. Perhatian = perlu dicek. Sertakan juga beberapa temuan "sesuai" untuk bagian penting yang sudah benar. "halaman" boleh null.
TXT;
    }

    private function extracted(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            if (! is_array($it) || ! is_string($it['field'] ?? null)) {
                continue;
            }
            $v = ClaimFields::normalize(trim($it['field']), $it['nilai'] ?? null);
            if ($v !== null && ! isset($out[$it['field']])) {
                $out[$it['field']] = $v;
            }
        }

        return $out;
    }

    /** Aturan E-Klaim dijalankan atas data yang terbaca dari dokumen; berkas sementara tidak disimpan. */
    private function ruleFindings(FtpFolder $folder, array $docs, array $extracted): array
    {
        $claim = new Claim(['nama_pasien' => $folder->nama_pasien, 'no_rm' => $folder->no_rm ?? '-', 'no_sep' => $folder->no_sep, 'jenis_rawat' => $folder->jenis_rawat]);
        foreach ($extracted as $field => $v) {
            ClaimFields::apply($claim, $field, $v);
        }
        $claim->setRelation('documents', collect($docs)->map(fn ($d) => new ClaimDocument(['type' => $d['type']->value, 'original_name' => $d['name']])));

        return array_map(fn ($f) => $f + ['dokumen' => null, 'halaman' => null, 'sumber' => 'aturan'], $this->rules->check($claim, fromDocuments: true));
    }

    private function findings(array $items, array $names): array
    {
        return collect($items)->filter(fn ($f) => is_array($f) && filled($f['judul'] ?? null))->map(fn ($f) => [
            'tingkat' => in_array($f['tingkat'] ?? '', ['kritis', 'perhatian', 'sesuai'], true) ? $f['tingkat'] : 'perhatian',
            'kategori' => mb_substr((string) ($f['kategori'] ?? 'Lainnya'), 0, 40),
            'judul' => IcdGuard::strip(mb_substr((string) $f['judul'], 0, 200)),
            'uraian' => IcdGuard::strip(mb_substr((string) ($f['uraian'] ?? ''), 0, 1000)),
            'dokumen' => $this->matchName($f['dokumen'] ?? null, $names),
            'halaman' => is_numeric($f['halaman'] ?? null) && (int) $f['halaman'] > 0 ? (int) $f['halaman'] : null,
            'sumber' => 'ai',
        ])->values()->all();
    }

    private function suggestions(array $items, array $names): array
    {
        return collect($items)->filter(fn ($s) => is_array($s) && filled($s['kondisi'] ?? null))->map(fn ($s) => [
            'kondisi' => IcdGuard::strip(mb_substr((string) $s['kondisi'], 0, 200)),
            'bukti' => IcdGuard::strip(mb_substr((string) ($s['bukti'] ?? ''), 0, 600)),
            'dokumen' => $this->matchName($s['dokumen'] ?? null, $names),
            'halaman' => is_numeric($s['halaman'] ?? null) && (int) $s['halaman'] > 0 ? (int) $s['halaman'] : null,
        ])->values()->all();
    }

    /** Nama file yang disebut AI dicocokkan ke nama file yang benar-benar ada, supaya tautannya bisa dibuka. */
    private function matchName(mixed $name, array $names): ?string
    {
        if (! is_string($name) || $name === '') {
            return null;
        }
        $norm = fn ($s) => mb_strtolower(preg_replace('/\.[a-z0-9]+$/i', '', trim($s)));
        foreach ($names as $n) {
            if ($norm($n) === $norm($name)) {
                return $n;
            }
        }
        foreach ($names as $n) {
            if (str_contains($norm($n), $norm($name)) || str_contains($norm($name), $norm($n))) {
                return $n;
            }
        }

        return mb_substr(trim($name), 0, 150); // tetap ditampilkan, tanpa tautan
    }
}
