<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Admin\SettingController;
use App\Models\CodeSuggestion;
use App\Models\ConditionSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AnggaranHabis;
use App\Services\Pnpk\PnpkAcuan;
use App\Services\Verification\FolderAnalyzer;
use App\Support\Audit;
use App\Support\VerificationRubric;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Tahap 2: isi rubrik tetap 14 butir (lihat VerificationRubric), bukan temuan bebas.
 * Konvergen: butir terkunci (lengkap/tidak berlaku/diterima) dengan dokumen sumber yang sama tidak dikirim ke AI
 * dan tidak diubah. Bila tidak ada butir yang perlu dinilai dan tidak perlu saran baru, AI tidak dipanggil.
 */
class SynthesizeFolderAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public const MAX_SUGGESTIONS = 5;

    public const JENIS = ['Diagnosis', 'Tindakan', 'Layanan'];

    public const MAX_CODES = 15;

    public function __construct(public int $analysisId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(AiManager $ai, FolderAnalyzer $analyzer, AiBudget $budget): void
    {
        $a = FolderAnalysis::with(['folder', 'documents'])->find($this->analysisId);
        if (! $a || ! $a->status?->isActive()) {
            return;
        }
        $folder = $a->folder;

        try {
            // Berhenti saat gagal: tahap 2 tidak memanggil AI bila ada dokumen yang gagal dibaca
            if ($failed = $a->documents->firstWhere('status', ReviewStatus::Gagal)) {
                throw new RuntimeException("Dihentikan: {$failed->name} gagal dibaca. Dokumen berikutnya tidak diproses.");
            }
            $config = $ai->defaultConfig();
            $req = $analyzer->synthesisRequest($a, $config);
            if (! $req) {
                $err = $a->documents->firstWhere('status', ReviewStatus::Gagal)?->error;
                throw new RuntimeException('Tidak ada dokumen yang berhasil dibaca AI' . ($err ? ": $err" : '.'));
            }

            $items = $folder->rubricItems()->get()->keyBy('kode');
            $need = collect(VerificationRubric::aiCodes())
                ->reject(fn ($k) => $items->get($k)?->lockedFor(VerificationRubric::fingerprints($folder, $k)))->values()->all();
            $saranOn = SettingController::conditionSuggestionsOn();
            $needSaran = $saranOn && $folder->suggestions_fingerprint !== $folder->fingerprint;
            $needKode = SettingController::codeSuggestionsOn() && ! CodeSuggestion::whereHas('analysis',
                fn ($q) => $q->where('ftp_folder_id', $folder->id)->where('fingerprint', $folder->fingerprint))->exists();

            // Acuan PNPK: hanya butir disetujui, dirujuk AI lewat id; id yang tidak dikirim dibuang
            $pnpk = app(PnpkAcuan::class)->butirForFolder($folder);
            // Hash gabungan file (FTP + manual) + versi set butir: sama dengan analisa selesai terakhir → AI tidak dipanggil lagi
            $hash = self::hashMasukan($a, $pnpk, [$need, $needSaran, $needKode]);
            $a->update(['hash_masukan' => $hash]);
            if (! $a->paksa && FolderAnalysis::where('ftp_folder_id', $folder->id)->whereKeyNot($a->id)
                ->where('status', ReviewStatus::Selesai->value)->latest('id')->value('hash_masukan') === $hash) {
                $need = [];
                $needSaran = $needKode = false;
            }

            $json = [];
            if ($need || $needSaran || $needKode) {
                if ($blocked = $budget->runBlocker('analisa')) {
                    throw new RuntimeException($blocked);
                }
                $req->refId = $a->id;
                $this->buildRequest($req, $a, $folder, $need, $needSaran, $needKode, $pnpk);
                $req->maxTokens = min((int) $config->max_tokens, ReadFolderDocument::MAX_TOKENS);
                $res = $ai->for($config)->generate($req);
                $budget->charge($a, $res, $config->id);
                ReadFolderDocument::ensureComplete($res, $req->maxTokens);
                $json = (array) $res->json();
            }

            $summary = VerificationRubric::words($json['ringkasan'] ?? null, 60)
                ?? $folder->analyses()->where('status', ReviewStatus::Selesai->value)->value('summary')
                ?? 'Tidak ada dokumen yang berubah; daftar periksa tetap.';
            // Aturan E-Klaim + data gabungan dihitung tanpa AI; "kondisi" ikut dibersihkan (kode ICD dibuang)
            $r = $analyzer->synthesize($a, ['ringkasan' => $summary, 'temuan' => [], 'kondisi' => $needSaran ? array_slice((array) ($json['kondisi'] ?? []), 0, self::MAX_SUGGESTIONS) : []]);
            if ($json && $pnpk->isNotEmpty()) {
                $r['findings'] = array_merge($r['findings'] ?? [], $this->pnpkFindings($pnpk, $json['pnpk'] ?? []));
            }

            $this->saveAiItems($folder, $items, $need, (array) ($json['rubrik'] ?? []));
            $this->saveRuleItems($folder, $items, collect($r['findings'] ?? []));
            if ($needKode) { // jalur saran kode: sengaja tidak lewat IcdGuard, hanya cek format
                $this->saveCodes($a, (array) ($json['kode'] ?? []));
            }
            if ($needSaran) {
                $this->saveSuggestions($folder, (array) ($r['suggestions'] ?? []));
            }
            $kurang = $folder->recomputeReadiness();

            $a->update([
                'status' => ReviewStatus::Selesai,
                'summary' => $r['summary'] ?? $summary,
                'findings' => $r['findings'] ?? [],
                'suggestions' => $needSaran ? ($r['suggestions'] ?? []) : [], // arsip; tampilan memakai condition_suggestions
                'extracted' => $r['extracted'] ?? null,
                'error' => null,
                'finished_at' => now(),
            ]);
            $folder->update([
                'analysis_status' => 'selesai',
                'analyzed_fingerprint' => $a->fingerprint,
                'kritis_count' => $a->countBy('kritis'),
                'perhatian_count' => $a->countBy('perhatian'),
                'analyzed_at' => now(),
            ]);
            Audit::system('folder.analisa_selesai', $folder, 'Analisa AI selesai: ' . $folder->readinessLabel()
                . ($json ? '' : ' (tanpa panggilan AI, semua butir terkunci)'), $a->requested_by,
                ['analisa' => $a->id, 'butir_dinilai_ai' => $need, 'butir_kurang' => $kurang]);
        } catch (AnggaranHabis $e) { // ditahan, bukan gagal; dilanjutkan manual setelah anggaran dinaikkan
            $a->update(['status' => ReviewStatus::MenungguAnggaran, 'error' => $e->getMessage()]);
            $folder->update(['analysis_status' => ReviewStatus::MenungguAnggaran->value]);
        } catch (Throwable $e) {
            $a->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $folder->update(['analysis_status' => 'gagal']);
            Audit::system('folder.analisa_gagal', $folder, 'Analisa AI gagal: ' . mb_substr($e->getMessage(), 0, 180), $a->requested_by);
            report($e);
        }
    }

    /** sha256 dari sidik file berkas (FTP + manual) dan versi butir PNPK yang disisipkan. */
    public static function hashMasukan(FolderAnalysis $a, Collection $pnpk, array $tugas = []): string
    {
        $files = $a->documents()->pluck('file_fingerprint', 'name')->sortKeys()->all();
        $butir = $pnpk->map(fn ($b) => $b->id . '@' . $b->updated_at?->timestamp)->sort()->values()->all();

        return hash('sha256', json_encode([$files, $butir, $tugas])); // $tugas: butir rubrik/saran yang perlu dinilai
    }

    /** Ganti instruksi tahap 2: hanya butir yang perlu dinilai (+ kondisi bila saran menyala). */
    /** Rujukan AI ke butir PNPK yang dikirim saja; id asing dibuang. */
    private function pnpkFindings(Collection $pnpk, mixed $items): array
    {
        $byId = $pnpk->keyBy('id');

        return array_map(fn ($r) => ['tingkat' => 'perhatian', 'kategori' => 'PNPK', 'judul' => 'Acuan PNPK: ' . $byId[$r['id']]->jenisLabel(),
            'uraian' => $r['catatan'] ?: $byId[$r['id']]->ringkasan, 'dokumen' => null, 'halaman' => null, 'sumber' => 'pnpk', 'pnpk_butir_id' => $r['id']],
            app(PnpkAcuan::class)->filterRujukan($items, $byId->keys()->all()));
    }

    private function buildRequest(AiRequest $req, FolderAnalysis $a, FtpFolder $folder, array $need, bool $needSaran, bool $needKode = false, ?Collection $pnpk = null): void
    {
        $butir = collect($need)->map(fn ($k) => "- $k: " . VerificationRubric::label($k))->implode("\n");
        $schema = '{"ringkasan":"maks 2 kalimat","rubrik":[{"kode":"...","status":"lengkap|kurang|tidak_berlaku","catatan":"maks 20 kata","dokumen":"nama file","halaman":1}]'
            . ($needSaran ? ',"kondisi":[{"jenis":"Diagnosis|Tindakan|Layanan","kondisi":"...","bukti":"kutipan maks 30 kata","dokumen":"nama file","halaman":1}]' : '')
            . ($needKode ? ',"kode":[{"system":"icd10|icd9cm","peran":"utama|sekunder|tindakan","code":"A00.0","title":"judul kode","alasan":"maks 20 kata","kaidah_koding":"aturan koding bila ada, boleh null","sumber":"resume|cppt|penunjang|lab|lainnya","bukti":"kutipan maks 30 kata","dokumen":"nama file","halaman":1,"keyakinan":"kuat|sedang","tercantum_di_resume":true}]' : '') . '}';

        $req->system = "Anda verifikator berkas klaim BPJS rumah sakit. Nilai HANYA butir rubrik yang diminta, berdasarkan hasil baca dokumen.\n"
            . "Status: lengkap (terpenuhi), kurang (tidak terpenuhi/tidak tertulis), tidak_berlaku (tidak relevan untuk pasien ini).\n"
            . "Jangan membuat butir lain. Jawab hanya JSON valid tanpa teks lain."
            . ($needKode ? "\nKhusus field \"kode\": Anda BOLEH menyarankan kode ICD-10 2010 dan ICD-9-CM 2010 sebagai bahan koder; keputusan tetap koder/DPJP. Di luar field itu jangan menulis kode." : '');

        $dokumen = $a->documents->map(fn ($d) => [
            'nama' => $d->name,
            'jenis' => VerificationRubric::docType($d->name),
            'hasil_baca' => $d->summary,
            'temuan' => $d->findings,
        ])->values()->all();

        $text = 'Pasien: ' . ($folder->nama_pasien ?: $folder->name) . ' · RM ' . ($folder->no_rm ?: '-') . ' · SEP ' . ($folder->no_sep ?: '-') . "\n"
            . 'Hasil baca per dokumen (tahap 1): ' . json_encode($dokumen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n"
            . ($need ? "Butir rubrik yang dinilai:\n$butir\n\n" : "Tidak ada butir rubrik yang perlu dinilai; isi \"rubrik\" dengan [].\n\n");

        if ($needSaran) {
            $text .= 'Kondisi: maks ' . self::MAX_SUGGESTIONS . ' kemungkinan terlewat di resume medis, jenis Diagnosis, Tindakan, atau Layanan: '
                . 'hal yang punya bukti TERTULIS di dokumen lain (terapi, penunjang abnormal, laporan operasi, rincian biaya, asesmen) tetapi tidak tertulis di resume. '
                . "Sertakan kutipan bukti, nama file, dan halaman. Tanpa kode ICD. Bila tidak ada, isi [].\n";
            $dijawab = $folder->conditionSuggestions()->where('status', '!=', 'baru')->get()
                ->filter(fn (ConditionSuggestion $s) => $s->source_fingerprints == VerificationRubric::fileFingerprint($folder, $s->dokumen))
                ->pluck('kondisi')->unique()->values();
            if ($dijawab->isNotEmpty()) {
                $text .= 'Sudah ditanyakan/dijawab DPJP, JANGAN diusulkan lagi: ' . $dijawab->implode('; ') . "\n";
            }
        }
        if ($needKode) {
            $text .= 'Kode: maks ' . self::MAX_CODES . ' saran dari hasil baca tahap 1: diagnosis utama (icd10, peran utama), diagnosis sekunder '
                . '(komplikasi/komorbid yang didukung lab, obat, atau CPPT; icd10, peran sekunder), dan tindakan (icd9cm, peran tindakan). '
                . 'WAJIB kutipan bukti, nama file, dan halaman; tanpa bukti jangan dimasukkan. Jangan mempertimbangkan tarif atau nilai klaim. '
                . "Format kode: ICD-10 mis. J18.9, ICD-9-CM mis. 99.04. Bila tidak ada, isi [].\n";
        }
        if ($pnpk && $pnpk->isNotEmpty()) {
            $schema = substr($schema, 0, -1) . ',"pnpk":[{"id":1,"catatan":"maks 25 kata: apakah dokumentasi sesuai butir ini"}]}';
            $text .= app(PnpkAcuan::class)->promptBlock($pnpk)
                . "\nPNPK: rujuk butir hanya lewat \"id\" yang dikirim, maks 5, bila dokumentasi tampak tidak sesuai atau perlu dicek. Bila tidak ada, isi [].\n";
        }
        $text .= "\nFormat JSON: $schema";

        $req->parts = [['type' => 'text', 'text' => $text]];
    }

    private function saveAiItems(FtpFolder $folder, Collection $items, array $need, array $rubrik): void
    {
        $byCode = collect($rubrik)->filter(fn ($r) => is_array($r) && isset($r['kode']))->keyBy(fn ($r) => (string) $r['kode']);
        foreach ($need as $kode) { // kode di luar rubrik / butir terkunci diabaikan
            $r = $byCode->get($kode);
            $status = in_array($r['status'] ?? null, ['lengkap', 'kurang', 'tidak_berlaku'], true) ? $r['status'] : 'kurang';
            $this->put($folder, $items, $kode, $status, $r ? ($r['catatan'] ?? null) : 'Tidak dinilai AI; analisa ulang.',
                VerificationRubric::matchFile($folder, $r['dokumen'] ?? null), $r['halaman'] ?? null);
        }
    }

    /** dok_* dari keberadaan jenis dokumen; aturan_eklaim dari temuan aturan (ConsistencyChecker). */
    private function saveRuleItems(FtpFolder $folder, Collection $items, Collection $findings): void
    {
        $jenis = ['dok_sep' => ['sep', 'SEP'], 'dok_resume' => ['resume', 'Resume medis'], 'dok_biaya' => ['biaya', 'Rincian biaya']];
        foreach ($jenis as $kode => [$type, $nama]) {
            if ($items->get($kode)?->lockedFor(VerificationRubric::fingerprints($folder, $kode))) {
                continue;
            }
            $ada = VerificationRubric::hasType($folder, $type);
            $this->put($folder, $items, $kode, $ada ? 'lengkap' : 'kurang', $ada ? null : "$nama tidak ada di folder.");
        }

        if (! $items->get('aturan_eklaim')?->lockedFor(VerificationRubric::fingerprints($folder, 'aturan_eklaim'))) {
            $aturan = $findings->filter(fn ($f) => ($f['sumber'] ?? null) === 'aturan' && ! str_contains(mb_strtolower($f['judul'] ?? ''), 'tidak ada di folder'))->values();
            $first = $aturan->first();
            $this->put($folder, $items, 'aturan_eklaim', $aturan->isEmpty() ? 'lengkap' : 'kurang',
                $aturan->pluck('judul')->implode('; ') ?: null, $first['dokumen'] ?? null, $first['halaman'] ?? null);
        }

        // Bersihkan butir di luar rubrik (tidak boleh ada)
        $folder->rubricItems()->whereNotIn('kode', VerificationRubric::codes())->delete();
    }

    private function put(FtpFolder $folder, Collection $items, string $kode, string $status, ?string $catatan, ?string $dokumen = null, $halaman = null): void
    {
        $item = $items->get($kode) ?? new RubricItem(['ftp_folder_id' => $folder->id, 'kode' => $kode]);
        $item->fill([
            'status' => $status,
            'catatan' => VerificationRubric::words($catatan, 20),
            'dokumen' => $dokumen,
            'halaman' => is_numeric($halaman) && (int) $halaman > 0 ? (int) $halaman : null,
            'source_fingerprints' => VerificationRubric::fingerprints($folder, $kode),
            'accepted_by' => null, 'accepted_reason' => null, 'accepted_at' => null,
            'evaluated_at' => now(),
        ])->save();
        $items->put($kode, $item);
    }

    /** Saran kode: format dicek (tanpa master), tanpa bukti/halaman dibuang. Tidak lewat IcdGuard. */
    private function saveCodes(FolderAnalysis $a, array $kode): void
    {
        $added = 0;
        foreach ($kode as $k) {
            if ($added >= self::MAX_CODES || ! is_array($k)) {
                continue;
            }
            $system = strtolower(trim((string) ($k['system'] ?? '')));
            $peran = strtolower(trim((string) ($k['peran'] ?? '')));
            $code = strtoupper(trim((string) ($k['code'] ?? '')));
            $bukti = trim((string) ($k['bukti'] ?? ''));
            $halaman = is_numeric($k['halaman'] ?? null) && (int) $k['halaman'] > 0 ? (int) $k['halaman'] : null;
            if ($bukti === '' || ! $halaman || ! in_array($peran, CodeSuggestion::PERAN, true)) {
                continue;
            }
            if (($peran === 'tindakan') !== ($system === 'icd9cm') || ! CodeSuggestion::validFormat($system, $code)) {
                Log::warning('Saran kode AI dibuang: format tidak valid', ['analisa' => $a->id, 'system' => $system, 'peran' => $peran, 'code' => $code]);
                continue;
            }
            $dokumen = VerificationRubric::matchFile($a->folder, $k['dokumen'] ?? null) ?? ($k['dokumen'] ?? null);
            CodeSuggestion::create([
                'folder_analysis_id' => $a->id,
                'system' => $system,
                'peran' => $peran,
                'code' => $code,
                'title' => mb_substr(trim((string) ($k['title'] ?? '')), 0, 255),
                'alasan' => mb_substr(trim((string) ($k['alasan'] ?? '')), 0, 500) ?: null,
                'kaidah_koding' => mb_substr(trim((string) ($k['kaidah_koding'] ?? '')), 0, 300) ?: null,
                'sumber' => in_array($k['sumber'] ?? null, CodeSuggestion::SUMBER, true) ? $k['sumber'] : 'lainnya',
                'bukti' => mb_substr($bukti, 0, 400),
                'folder_document_id' => $dokumen ? $a->documents->firstWhere('name', $dokumen)?->id : null,
                'halaman' => $halaman,
                'keyakinan' => ($k['keyakinan'] ?? null) === 'kuat' ? 'kuat' : 'sedang',
                'tercantum_di_resume' => filter_var($k['tercantum_di_resume'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ]);
            $added++;
        }
    }

    /** Saran baru menggantikan saran "baru" lama; yang sudah ditanyakan/dijawab dengan dokumen sama tidak diduplikasi. */
    private function saveSuggestions(FtpFolder $folder, array $saran): void
    {
        $folder->conditionSuggestions()->where('status', 'baru')->delete();
        $kept = $folder->conditionSuggestions()->get();
        $added = 0;
        foreach ($saran as $s) {
            $kondisi = trim((string) ($s['kondisi'] ?? ''));
            $jenis = collect(self::JENIS)->first(fn ($j) => strcasecmp($j, trim((string) ($s['jenis'] ?? ''))) === 0);
            if ($jenis && $kondisi !== '' && ! str_starts_with(mb_strtolower($kondisi), mb_strtolower($jenis) . ':')) {
                $kondisi = "$jenis: $kondisi";
            }
            if ($kondisi === '' || $added >= self::MAX_SUGGESTIONS) {
                continue;
            }
            $dokumen = VerificationRubric::matchFile($folder, $s['dokumen'] ?? null) ?? ($s['dokumen'] ?? null);
            $fp = VerificationRubric::fileFingerprint($folder, $dokumen);
            $dup = $kept->contains(fn (ConditionSuggestion $k) => ConditionSuggestion::similar($k->kondisi, $kondisi)
                && ($k->status === 'ditanyakan' || $k->source_fingerprints == $fp));
            if ($dup) {
                continue;
            }
            $kept->push($folder->conditionSuggestions()->create([
                'kondisi' => mb_substr($kondisi, 0, 255),
                'bukti' => VerificationRubric::words($s['bukti'] ?? null, 30),
                'dokumen' => $dokumen,
                'halaman' => is_numeric($s['halaman'] ?? null) && (int) $s['halaman'] > 0 ? (int) $s['halaman'] : null,
                'source_fingerprints' => $fp,
                'status' => 'baru',
            ]));
            $added++;
        }
        $folder->update(['suggestions_fingerprint' => $folder->fingerprint]);
    }
}
