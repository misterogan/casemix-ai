<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\ReadFolderDocument;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\ConditionSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\FolderSync;
use App\Support\Audit;
use App\Support\VerificationRubric;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Verifikasi berkas langsung dari folder FTP Enigma: tanpa pengisian, hanya rekomendasi AI. */
class FolderController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate([
            'sep' => ['nullable', 'string', 'max:30'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date'],
            'periksa' => ['nullable', 'in:sudah,belum'],
            'kesiapan' => ['nullable', 'in:siap,belum_siap'],
        ]);

        $sep = strtoupper(preg_replace('/\s+/', '', (string) ($v['sep'] ?? '')));
        $dari = filled($v['dari'] ?? null) ? Carbon::parse($v['dari'])->startOfDay() : null;
        $sampai = filled($v['sampai'] ?? null) ? Carbon::parse($v['sampai'])->endOfDay() : null;
        if ($dari && $sampai && $dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai->copy()->startOfDay(), $dari->copy()->endOfDay()];
        }
        $periksa = $v['periksa'] ?? '';
        $kesiapan = $v['kesiapan'] ?? '';

        // Filter No. SEP dan tanggal; status pemeriksaan ditambahkan terpisah supaya jumlah per status bisa dihitung
        $base = fn () => FtpFolder::query()
            ->whereNull('missing_at')
            ->when($sep !== '', fn ($w) => $w->where('no_sep', 'like', "%$sep%"))
            ->when($dari, fn ($w) => $w->where('files_modified_at', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('files_modified_at', '<=', $sampai))
            ->when($kesiapan === 'siap', fn ($w) => $w->where('rubric_kurang', 0))
            ->when($kesiapan === 'belum_siap', fn ($w) => $w->where('rubric_kurang', '>', 0));
        $byStatus = fn ($w, string $p) => match ($p) {
            'sudah' => $w->where('analysis_status', 'selesai'),
            'belum' => $w->whereNull('analysis_status'),
            default => $w,
        };

        return view('folders.index', [
            'folders' => $byStatus($base(), $periksa)->orderByDesc('files_modified_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => ['sep' => $sep, 'dari' => $dari?->toDateString(), 'sampai' => $sampai?->toDateString(), 'periksa' => $periksa, 'kesiapan' => $kesiapan],
            'counts' => ['semua' => $base()->count(), 'sudah' => $byStatus($base(), 'sudah')->count(), 'belum' => $byStatus($base(), 'belum')->count()],
            'lastSync' => AppSetting::get(FolderSync::KEY_LAST),
        ]);
    }

    /** Dibuka pertama kali: analisa AI dimulai otomatis. Berikutnya: hasil tersimpan, tanpa memanggil AI. */
    public function show(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        $latest = $folder->analyses()->first();
        $blocker = $this->startBlocker($budget);

        if (! $latest && ! $folder->missing_at && ! $blocker && $request->user()->can('folders.analyze')) {
            $latest = $this->start($folder, $request->user()->id);
            Audit::log('folder.analisa', $folder, 'Membuka folder pertama kali; analisa AI dimulai otomatis');
        }

        $data = $this->viewData($folder, $latest, $blocker);

        // Dipanggil polling saat analisa selesai: hanya isi halaman, ditukar tanpa memuat ulang
        if ($request->ajax()) {
            return response()->view('folders.partials.detail', $data)->header('Vary', 'X-Requested-With');
        }

        return view('folders.show', $data);
    }

    public function analyze(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        abort_if($folder->missing_at, 404);
        if ($folder->analyses()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }
        $this->start($folder, $request->user()->id);
        Audit::log('folder.analisa_ulang', $folder, 'Meminta analisa ulang folder');

        return back()->with('ok', 'Analisa ulang dimulai. Halaman diperbarui otomatis saat selesai.');
    }

    /** Dipanggil polling tiap 3 detik: status dan HTML tiap file, supaya halaman diperbarui tanpa dimuat ulang. */
    public function status(FtpFolder $folder, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        $data = $this->viewData($folder, $a, $this->startBlocker($budget));
        $docs = $data['documents'];
        $active = (bool) $a?->status?->isActive();

        return response()->json([
            'active' => $active,
            'done' => $docs->reject(fn (FolderDocument $d) => $d->status->isActive())->count(),
            'total' => $docs->count(),
            'stuck' => $docs->contains(fn (FolderDocument $d) => in_array($d->status, [ReviewStatus::Gagal, ReviewStatus::Dibatalkan], true)),
            'can_resynth' => $data['canResynth'],
            'docs' => $docs->map(fn (FolderDocument $d) => [
                'id' => $d->id,
                'status' => $d->status->value,
                'html' => view('folders.partials.document', $data + ['d' => $d, 'berjalan' => $active])->render(),
            ])->values(),
            'summary_html' => $active ? null : view('folders.partials.detail', $data)->render(),
        ]);
    }

    /** Ulangi tahap 2 saja (rekomendasi keseluruhan) untuk analisa terakhir. File TIDAK dibaca ulang. */
    public function resynthesize(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        abort_unless($a, 404);
        if ($a->status?->isActive()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if (! $this->canResynth($a)) {
            return back()->with('warn', 'Rekomendasi keseluruhan hanya bisa diulang bila semua file sudah selesai dibaca dan hasilnya belum ada.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }

        $a->update(['status' => ReviewStatus::Berjalan, 'error' => null]);
        $folder->update(['analysis_status' => 'berjalan']);
        SynthesizeFolderAnalysis::dispatch($a->id);
        Audit::log('folder.ulang_rekomendasi', $folder, 'Meminta rekomendasi keseluruhan dibuat ulang (file tidak dibaca ulang)');

        return back()->with('ok', 'Rekomendasi keseluruhan sedang dibuat ulang.');
    }

    /** Baca ulang SEMUA file (tanpa memakai hasil lama), lalu periksa silang. */
    public function processAll(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        abort_if($folder->missing_at, 404);
        if ($folder->analyses()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }
        $this->start($folder, $request->user()->id, all: true);
        Audit::log('folder.proses_semua', $folder, 'Meminta semua file dibaca ulang');

        return back()->with('ok', 'Semua file dibaca ulang. Hasil tampil per file saat selesai.');
    }

    /** Baca ulang satu file, lalu periksa silang. File lain tidak dibaca ulang. */
    public function processDocument(Request $request, FtpFolder $folder, FolderDocument $document, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        abort_unless($a && $document->folder_analysis_id === $a->id, 404);
        if ($a->status?->isActive()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if ($a->documents()->whereKeyNot($document->id)->where('status', ReviewStatus::Gagal->value)->exists()) {
            return back()->with('warn', 'Ada file lain yang gagal dibaca. Gunakan tombol Lanjutkan dari dokumen yang gagal.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }

        $document->update(['status' => ReviewStatus::Antre, 'error' => null, 'reused' => false]);
        $a->update(['status' => ReviewStatus::Berjalan, 'error' => null]);
        $folder->update(['analysis_status' => 'berjalan']);
        Bus::chain([new ReadFolderDocument($document->id), new SynthesizeFolderAnalysis($a->id)])->onQueue(config('casemix.ai.queue'))->dispatch();
        Audit::log('folder.proses_file', $folder, 'Meminta file dibaca ulang: ' . $document->name);

        return back()->with('ok', 'File dibaca ulang. Hasil tampil saat selesai.');
    }

    /** Lanjutkan analisa yang berhenti: baca ulang dokumen gagal + dibatalkan, lalu periksa silang. Dokumen selesai tidak dibaca ulang. */
    public function resume(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        abort_unless($a, 404);
        if ($a->status?->isActive()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        $pending = $a->documents()->whereIn('status', [ReviewStatus::Gagal->value, ReviewStatus::Dibatalkan->value])->get();
        if ($pending->isEmpty()) {
            return back()->with('warn', 'Tidak ada dokumen yang perlu dilanjutkan.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }

        FolderDocument::whereKey($pending->modelKeys())->update(['status' => ReviewStatus::Antre->value, 'error' => null, 'reused' => false]);
        $a->update(['status' => ReviewStatus::Berjalan, 'error' => null]);
        $folder->update(['analysis_status' => 'berjalan']);
        $jobs = $pending->sortBy('file_index')->map(fn ($d) => new ReadFolderDocument($d->id))->values()->all();
        $jobs[] = new SynthesizeFolderAnalysis($a->id);
        Bus::chain($jobs)->onQueue(config('casemix.ai.queue'))->dispatch();
        Audit::log('folder.analisa_lanjut', $folder, 'Melanjutkan analisa dari dokumen yang gagal (' . $pending->count() . ' dokumen)');

        return back()->with('ok', 'Analisa dilanjutkan. Halaman diperbarui otomatis saat selesai.');
    }

    /** Koordinator menerima butir yang kurang (alasan wajib). Butir diterima dianggap tidak menghalangi Siap diajukan. */
    public function acceptRubric(Request $request, FtpFolder $folder, string $kode)
    {
        abort_unless(in_array($kode, VerificationRubric::codes(), true), 404);
        $v = $request->validate(['alasan' => ['required', 'string', 'min:3', 'max:500']]);
        $item = $folder->rubricItems()->where('kode', $kode)->firstOrFail();
        if ($item->status !== 'kurang') {
            return back()->with('warn', 'Hanya butir berstatus Kurang yang bisa diterima.');
        }
        $item->update(['status' => 'diterima', 'accepted_by' => $request->user()->id, 'accepted_reason' => $v['alasan'], 'accepted_at' => now()]);
        $folder->recomputeReadiness();
        Audit::log('folder.rubrik_terima', $folder, 'Menerima butir "' . $item->label() . '": ' . $v['alasan'], ['kode' => $kode, 'alasan' => $v['alasan']]);

        return back()->with('ok', 'Butir "' . $item->label() . '" diterima.');
    }

    public function unacceptRubric(Request $request, FtpFolder $folder, string $kode)
    {
        abort_unless(in_array($kode, VerificationRubric::codes(), true), 404);
        $item = $folder->rubricItems()->where('kode', $kode)->where('status', 'diterima')->firstOrFail();
        $alasan = $item->accepted_reason;
        $item->update(['status' => 'kurang', 'accepted_by' => null, 'accepted_reason' => null, 'accepted_at' => null]);
        $folder->recomputeReadiness();
        Audit::log('folder.rubrik_batal_terima', $folder, 'Membatalkan penerimaan butir "' . $item->label() . '"', ['kode' => $kode, 'alasan_lama' => $alasan]);

        return back()->with('ok', 'Penerimaan butir "' . $item->label() . '" dibatalkan.');
    }

    /** Catat tindak lanjut saran DPJP. Tidak memengaruhi status Siap diajukan. */
    public function suggestionStatus(Request $request, FtpFolder $folder, ConditionSuggestion $suggestion)
    {
        abort_unless($suggestion->ftp_folder_id === $folder->id, 404);
        $v = $request->validate([
            'status' => ['required', 'in:' . implode(',', array_keys(ConditionSuggestion::STATUSES))],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);
        $lama = $suggestion->status;
        $suggestion->update([
            'status' => $v['status'],
            'catatan_respons' => $v['catatan'] ?? $suggestion->catatan_respons,
            'updated_by' => $request->user()->id,
            'responded_at' => in_array($v['status'], ConditionSuggestion::ANSWERED, true) ? now() : $suggestion->responded_at,
        ]);
        Audit::log('folder.saran_status', $folder, sprintf('Saran "%s": %s → %s', $suggestion->kondisi,
            ConditionSuggestion::STATUSES[$lama] ?? $lama, ConditionSuggestion::STATUSES[$v['status']]),
            ['saran' => $suggestion->id, 'dari' => $lama, 'ke' => $v['status'], 'catatan' => $v['catatan'] ?? null]);

        return back()->with('ok', 'Tindak lanjut saran dicatat.');
    }

    /** Buka satu dokumen: dari salinan arsip bila ada, bila tidak dari FTP lalu disimpan salinannya. Dipilih lewat nomor urut, bukan path, supaya tidak bisa diarahkan ke file lain. */
    public function file(FtpFolder $folder, int $index, SimrsFtp $ftp)
    {
        $f = $folder->fileAt($index);
        abort_unless($f, 404);

        $doc = FolderDocument::where('ftp_folder_id', $folder->id)->where('name', $f['name'])
            ->where('file_fingerprint', FolderDocument::fingerprintOf($f))->latest('id')->first();
        $contents = ReadFolderDocument::archivedContents($doc && ! $doc->stored_path ? ReadFolderDocument::storedSibling($doc) : $doc);
        if ($contents === null) {
            $disk = Storage::disk(config('casemix.storage_disk'));
            $tmp = 'tmp/lihat/' . Str::ulid();
            try {
                $contents = $disk->get($ftp->download($f['path'], $tmp . '/dokumen'));
            } finally {
                $disk->deleteDirectory($tmp);
            }
            if ($doc && ReadFolderDocument::archiveEnabled()) {
                ReadFolderDocument::archiveContents($doc, $contents);
            }
        }
        Audit::log('folder.buka_dokumen', $folder, 'Membuka ' . $f['name']);

        $mime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][Str::lower(pathinfo($f['name'], PATHINFO_EXTENSION))] ?? null;

        return response($contents, 200, [
            'Content-Type' => $mime ?? 'application/octet-stream',
            'Content-Disposition' => ($mime ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $f['name']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function startBlocker(AiBudget $budget): ?string
    {
        if (! AiProvider::default()) {
            return 'Belum ada penyedia AI yang aktif. Minta Admin mengatur Penyedia AI.';
        }

        return $budget->startBlocker();
    }

    /** @param bool $all true = semua file dibaca ulang; false = hanya file yang berubah */
    private function start(FtpFolder $folder, int $userId, bool $all = false): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $userId]);
        $folder->update(['analysis_status' => 'antre', 'last_analysis_id' => $a->id]);
        app(FolderAnalyzer::class)->prepare($a); // daftar dokumen langsung tampil sebagai "menunggu"
        if ($all) {
            $a->documents()->update(['status' => ReviewStatus::Antre->value, 'reused' => false, 'summary' => null, 'findings' => null, 'finished_at' => null]);
        }
        RunFolderAnalysis::dispatch($a->id);

        return $a;
    }

    private function viewData(FtpFolder $folder, ?FolderAnalysis $latest, ?string $blocker): array
    {
        $done = $latest?->status === ReviewStatus::Selesai ? $latest : $folder->analyses()->where('status', ReviewStatus::Selesai->value)->first();
        $saranOn = (bool) AppSetting::get('ai_condition_suggestions', false);
        $rubric = $folder->rubricItems()->get()->keyBy('kode');

        return [
            'folder' => $folder,
            'latest' => $latest,
            'done' => $done,
            'changed' => $done && $done->fingerprint !== $folder->fingerprint,
            'blocker' => $blocker,
            'documents' => $latest ? $this->withArchive($latest->documents()->get()) : collect(),
            'linkable' => collect($folder->files)->pluck('name')->flip(),
            'canResynth' => $latest ? $this->canResynth($latest) : false,
            // Daftar periksa: 14 butir tetap, urut sesuai rubrik, dikelompokkan
            'rubric' => collect(VerificationRubric::codes())->map(fn ($k) => $rubric->get($k) ?? new RubricItem(['kode' => $k, 'status' => null]))
                ->groupBy(fn (RubricItem $i) => VerificationRubric::group($i->kode)),
            'saranOn' => $saranOn,
            'suggestions' => $saranOn ? $folder->conditionSuggestions()->get() : collect(),
        ];
    }

    /** Dokumen yang dipakai ulang mewarisi salinan arsip dari dokumen sebelumnya dengan file yang sama. */
    private function withArchive($docs)
    {
        foreach ($docs->whereNull('stored_path') as $d) {
            if ($s = ReadFolderDocument::storedSibling($d)) {
                $d->update($s->only(['stored_path', 'stored_sha256', 'stored_size', 'stored_at']));
            }
        }

        return $docs;
    }

    /** Semua file analisa terakhir selesai dibaca, tetapi tahap 2 gagal atau belum ada hasilnya. */
    private function canResynth(FolderAnalysis $a): bool
    {
        if ($a->status?->isActive() || $a->status === ReviewStatus::Selesai) {
            return false;
        }
        $docs = $a->documents()->get(['id', 'status']);

        return $docs->isNotEmpty() && $docs->every(fn (FolderDocument $d) => $d->status === ReviewStatus::Selesai);
    }
}
