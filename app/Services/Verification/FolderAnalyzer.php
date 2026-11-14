<?php

namespace App\Services\Verification;

use App\Enums\ReviewStatus;
use App\Models\AiProvider as ProviderConfig;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Services\Ai\AiRequest;
use App\Services\Documents\DocumentReader;
use App\Services\Import\DocumentGuesser;
use App\Services\Import\SimrsFtp;
use App\Services\Rules\ConsistencyChecker;
use App\Support\ClaimFields;
use App\Support\Eklaim;
use App\Support\IcdGuard;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Verifikasi folder FTP dalam dua tahap:
 * 1. Setiap file dibaca AI sendiri-sendiri (satu panggilan per file); hasilnya disimpan per dokumen dan
 *    dipakai ulang selama file tidak berubah.
 * 2. Periksa silang antardokumen dengan satu panggilan teks dari ringkasan tahap 1 (tanpa gambar).
 * File diunduh ke folder sementara dan dihapus setelah dibaca; tidak ada salinan yang disimpan.
 */
class FolderAnalyzer
{
    private const MAX_TOKENS = 2000;

    private const SUMMARY_KEYS = ['identitas', 'isian', 'diagnosis', 'tindakan', 'terapi_penting', 'penunjang', 'biaya', 'ttd_dpjp'];

    public function __construct(private SimrsFtp $ftp, private DocumentReader $reader, private ConsistencyChecker $rules) {}

    // ------------------------------------------------------------ persiapan

    /** Buat baris dokumen untuk tiap file. File yang tidak berubah sejak dibaca sebelumnya memakai hasil lama tanpa AI. */
    public function prepare(FolderAnalysis $a): void
    {
        if ($a->documents()->exists()) {
            return;
        }
        $folder = $a->folder;
        $a->update(['fingerprint' => $folder->fingerprint, 'files_snapshot' => $folder->files]);

        foreach (array_values($folder->files ?? []) as $i => $f) {
            $fp = FolderDocument::fingerprintOf($f);
            $prev = FolderDocument::where('ftp_folder_id', $folder->id)->where('name', $f['name'])->where('file_fingerprint', $fp)
                ->where('status', ReviewStatus::Selesai->value)->latest('id')->first();

            FolderDocument::create([
                'folder_analysis_id' => $a->id,
                'ftp_folder_id' => $folder->id,
                'file_index' => $i,
                'name' => $f['name'],
                'file_fingerprint' => $fp,
                'doc_type' => DocumentGuesser::type($f['name'])->value,
                'status' => $prev ? ReviewStatus::Selesai : ReviewStatus::Antre,
                'reused' => (bool) $prev,
                'summary' => $prev?->summary,
                'findings' => $prev?->findings,
                'finished_at' => $prev ? now() : null,
            ]);
        }
    }

    // ------------------------------------------------------------ tahap 1: per dokumen

    /** Unduh dan baca satu file menjadi permintaan AI. Null bila file bukan PDF/gambar (tidak dibaca AI). */
    public function documentRequest(FolderDocument $d, ProviderConfig $config): ?AiRequest
    {
        $folder = $d->folder;
        $f = collect($folder->files)->firstWhere('name', $d->name) ?? throw new RuntimeException('File tidak ada lagi di folder FTP.');
        $disk = Storage::disk(config('casemix.storage_disk'));
        $tmp = 'tmp/analisa/' . Str::ulid();

        try {
            $local = $this->ftp->download($f['path'], $tmp . '/dokumen.' . Str::lower(pathinfo($f['name'], PATHINFO_EXTENSION)));
            $mime = $disk->mimeType($local) ?: 'application/octet-stream';
            if (! in_array($mime, config('casemix.upload.content_types'), true)) {
                return null;
            }
            $abs = $disk->path($local);

            $req = new AiRequest($config->model_standard, $this->systemPrompt(), min((int) $config->max_tokens, self::MAX_TOKENS));
            $req->text($this->documentInstruction($folder, $d));
            $head = "\n=== DOKUMEN: \"{$d->name}\" (perkiraan jenis: {$d->typeLabel()}) ===";

            $pages = $mime === 'application/pdf' ? $this->reader->pdfPageTexts($abs) : null;
            if ($pages) {
                $maxChars = (int) config('casemix.ai.max_text_chars_per_doc');
                $text = '';
                foreach ($pages as $p => $t) {
                    $text .= "\n[Halaman $p]\n$t";
                }
                $cut = mb_strlen($text) > $maxChars;
                $req->text($head . ($cut ? "\n(TERPOTONG: hanya sebagian halaman terkirim. Jangan menyimpulkan sesuatu tidak ada di dokumen ini.)" : '') . mb_substr($text, 0, $maxChars));

                return $req;
            }

            $images = $mime === 'application/pdf'
                ? $this->reader->renderPdf($abs, (int) config('casemix.ai.max_pages_per_scan'))
                : [$this->reader->read($abs, $mime)['images'][0] ?? null];
            $images = array_values(array_filter($images));
            if (! $images) {
                throw new RuntimeException('Isi dokumen tidak bisa dibaca (bukan teks dan tidak bisa dijadikan gambar).');
            }
            $req->text($head . "\n(Hasil scan. " . count($images) . ' halaman pertama terlampir sebagai gambar, berurutan dari halaman 1.)');
            foreach ($images as $p => $img) {
                $req->text('[Halaman ' . ($p + 1) . ']');
                $req->image($img['media_type'], $img['data']);
            }

            return $req;
        } finally {
            $disk->deleteDirectory($tmp);
        }
    }

    /** @return array{summary: array, findings: array} */
    public function parseDocument(FolderDocument $d, array $json): array
    {
        $findings = collect($json['temuan'] ?? [])->filter(fn ($f) => is_array($f) && filled($f['judul'] ?? null))->take(5)->map(fn ($f) => [
            'tingkat' => ($f['tingkat'] ?? '') === 'kritis' ? 'kritis' : 'perhatian',
            'kategori' => 'Dokumen',
            'judul' => IcdGuard::strip(mb_substr((string) $f['judul'], 0, 200)),
            'uraian' => IcdGuard::strip(mb_substr((string) ($f['uraian'] ?? ''), 0, 600)),
            'dokumen' => $d->name,
            'halaman' => $this->page($f['halaman'] ?? null),
            'sumber' => 'ai',
        ])->values()->all();

        return ['summary' => $this->clean(Arr::only($json, self::SUMMARY_KEYS)), 'findings' => $findings];
    }

    // ------------------------------------------------------------ tahap 2: periksa silang

    /** Permintaan teks saja dari ringkasan dokumen yang terbaca, atau null bila tidak ada yang terbaca. */
    public function synthesisRequest(FolderAnalysis $a, ProviderConfig $config): ?AiRequest
    {
        $read = $this->readable($a->documents);
        if ($read->isEmpty()) {
            return null;
        }
        $data = $read->map(fn (FolderDocument $d) => ['dokumen' => $d->name, 'jenis' => $d->typeLabel(), 'isi' => $d->summary])->values()->all();
        $unread = $a->documents->reject(fn ($d) => $read->contains('id', $d->id))->pluck('name')->all();
        $folder = $a->folder;

        $req = new AiRequest($config->model_standard, $this->systemPrompt(), min((int) $config->max_tokens, self::MAX_TOKENS));
        $req->text(<<<TXT
Folder FTP: "{$folder->name}" (dari nama folder: RM {$folder->no_rm}, nama {$folder->nama_pasien}, SEP {$folder->no_sep}).
Berikut ringkasan setiap dokumen (sudah dibaca terpisah). Periksa SILANG antardokumen:
1. Identitas dan No. SEP sama di semua dokumen.
2. Tanggal masuk/keluar konsisten.
3. Diagnosis di resume didukung hasil penunjang.
4. Tindakan punya laporan atau hasilnya.
5. Biaya di rincian biaya punya bukti layanan.
6. Kondisi dengan bukti klinis yang belum tertulis sebagai diagnosis (untuk konfirmasi DPJP).
Masalah di dalam satu dokumen saja sudah dilaporkan; jangan diulang.
TXT
            . ($unread ? "\nDokumen yang TIDAK terbaca (jangan menyimpulkan isinya tidak ada): " . implode(', ', $unread) : '')
            . "\n\nRINGKASAN DOKUMEN (JSON):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . <<<'TXT'


Jawab HANYA satu objek JSON ringkas tanpa spasi atau baris baru tambahan, tanpa ```:
{"ringkasan":"maks 2 kalimat","temuan":[{"tingkat":"kritis|perhatian","kategori":"Identitas|Tanggal|Diagnosis|Tindakan|Biaya|Kelengkapan|Lainnya","judul":"singkat","uraian":"maks 25 kata","dokumen":"nama file persis","halaman":1}],"kondisi":[{"kondisi":"...","bukti":"maks 25 kata","dokumen":"nama file persis","halaman":1}]}
Maksimal 8 temuan dan 3 kondisi. "halaman" boleh null.
TXT);

        return $req;
    }

    /** Gabungkan hasil: temuan per dokumen + temuan silang + dokumen gagal + aturan E-Klaim. */
    public function synthesize(FolderAnalysis $a, array $json): array
    {
        $docs = $a->documents;
        $names = $docs->pluck('name')->all();

        $failed = $docs->where('status', ReviewStatus::Gagal)->map(fn (FolderDocument $d) => [
            'tingkat' => 'perhatian',
            'kategori' => 'Kelengkapan',
            'judul' => 'Dokumen gagal dibaca AI',
            'uraian' => mb_substr($d->name . ': ' . ($d->error ?: 'tidak diketahui') . '. Cek manual atau klik "Baca ulang dokumen ini".', 0, 600),
            'dokumen' => $d->name,
            'halaman' => null,
            'sumber' => 'sistem',
        ])->values()->all();

        $extracted = $this->extracted($this->readable($docs)->sortBy(fn ($d) => $this->priority($d))->flatMap(fn ($d) => $d->summary['isian'] ?? [])->all());

        return [
            'summary' => IcdGuard::strip(trim((string) ($json['ringkasan'] ?? ''))),
            'findings' => array_merge(
                $docs->flatMap(fn ($d) => $d->findings ?? [])->values()->all(),
                $this->findings(array_slice($json['temuan'] ?? [], 0, 8), $names),
                $failed,
                $this->ruleFindings($a->folder, $docs, $extracted),
            ),
            'suggestions' => $this->suggestions(array_slice($json['kondisi'] ?? [], 0, 3), $names),
            'extracted' => $extracted,
        ];
    }

    /** Dokumen yang selesai dibaca dan punya ringkasan (bukan file yang dilewati). */
    public function readable(Collection $docs): Collection
    {
        return $docs->filter(fn (FolderDocument $d) => $d->status === ReviewStatus::Selesai && is_array($d->summary))->values();
    }

    // ------------------------------------------------------------ prompt

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

    private function documentInstruction(FtpFolder $folder, FolderDocument $d): string
    {
        $opsi = fn (array $m) => implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($m), $m));
        $tarif = implode(', ', array_keys(Eklaim::TARIF));

        return <<<TXT
Folder FTP: "{$folder->name}" (dari nama folder: RM {$folder->no_rm}, nama {$folder->nama_pasien}, SEP {$folder->no_sep}).
Baca SATU dokumen di bawah ini saja. Catat isinya untuk diperiksa silang nanti, dan laporkan masalah di dalam dokumen ini (mis. tanda tangan/nama DPJP kosong, tanggal tidak terbaca, identitas tidak sama dengan nama folder).

"isian" hanya field yang tertulis jelas (jangan menebak): tanggal_masuk, tanggal_keluar, tanggal_lahir (YYYY-MM-DD), jenis_rawat ({$opsi(Eklaim::JENIS_RAWAT)}), kelas_hak ({$opsi(Eklaim::KELAS)}), kelas_rawat ({$opsi(Eklaim::KELAS_RAWAT)}), cara_masuk ({$opsi(Eklaim::CARA_MASUK)}), cara_pulang ({$opsi(Eklaim::CARA_PULANG)}), berat_lahir (gram), rawat_intensif_hari, ventilator_jam, dpjp, diagnosis_utama (kata-kata), tarif.<komponen> hanya yang nilainya > 0 (komponen: $tarif).

Jawab HANYA satu objek JSON ringkas tanpa spasi atau baris baru tambahan, tanpa ```:
{"identitas":{"nama":"","no_rm":"","no_sep":""},"isian":[{"field":"","nilai":""}],"diagnosis":[],"tindakan":[],"terapi_penting":[],"penunjang":[{"pemeriksaan":"","hasil":""}],"biaya":[{"komponen":"","nilai":0}],"ttd_dpjp":true,"temuan":[{"tingkat":"kritis|perhatian","judul":"","uraian":"","halaman":1}]}
Batas: hanya yang tertulis di dokumen; kosongkan bagian yang tidak ada; ttd_dpjp null bila tidak relevan; maksimal 5 temuan; uraian maks 25 kata; tanpa kode ICD; tanpa nomor kartu peserta. Kritis = harus diselesaikan sebelum klaim diajukan. Perhatian = perlu dicek. "halaman" boleh null.
TXT;
    }

    // ------------------------------------------------------------ pengolahan

    /** SEP lebih dipercaya untuk data administrasi, lalu resume, lalu rincian biaya. */
    private function priority(FolderDocument $d): int
    {
        $l = mb_strtolower($d->typeLabel());

        return match (true) {
            str_contains($l, 'sep') => 0,
            str_contains($l, 'resume') => 1,
            str_contains($l, 'biaya') || str_contains($l, 'rincian') => 2,
            default => 3,
        };
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
    private function ruleFindings(FtpFolder $folder, Collection $docs, array $extracted): array
    {
        $claim = new Claim(['nama_pasien' => $folder->nama_pasien, 'no_rm' => $folder->no_rm ?? '-', 'no_sep' => $folder->no_sep, 'jenis_rawat' => $folder->jenis_rawat]);
        foreach ($extracted as $field => $v) {
            ClaimFields::apply($claim, $field, $v);
        }
        $claim->setRelation('documents', $docs->map(fn ($d) => new ClaimDocument(['type' => $d->doc_type, 'original_name' => $d->name]))->values());

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
            'halaman' => $this->page($f['halaman'] ?? null),
            'sumber' => 'ai',
        ])->values()->all();
    }

    private function suggestions(array $items, array $names): array
    {
        return collect($items)->filter(fn ($s) => is_array($s) && filled($s['kondisi'] ?? null))->map(fn ($s) => [
            'kondisi' => IcdGuard::strip(mb_substr((string) $s['kondisi'], 0, 200)),
            'bukti' => IcdGuard::strip(mb_substr((string) ($s['bukti'] ?? ''), 0, 600)),
            'dokumen' => $this->matchName($s['dokumen'] ?? null, $names),
            'halaman' => $this->page($s['halaman'] ?? null),
        ])->values()->all();
    }

    private function page(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    /** Semua teks dari AI dipotong dan dibersihkan dari kode ICD sebelum disimpan. */
    private function clean(array $data): array
    {
        array_walk_recursive($data, function (&$v) {
            if (is_string($v)) {
                $v = IcdGuard::strip(mb_substr($v, 0, 500));
            }
        });

        return $data;
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
