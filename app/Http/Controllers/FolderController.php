<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\ReadFolderDocument;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Http\Controllers\Admin\SettingController;
use App\Models\CodeSuggestion;
use App\Models\ConditionSuggestion;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Models\RubricItem;
use App\Services\Ai\AiBudget;
use App\Services\Regulasi\DasarKoding;
use App\Services\Regulasi\DasarSanggahan;
use App\Services\Regulasi\DrafSanggahan;
use App\Services\Vclaim\VclaimHistoriService;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\FolderSync;
use App\Services\Verification\VerifikasiLatar;
use App\Support\Audit;
use App\Support\VerificationRubric;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
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
            'eklaim' => ['nullable', 'in:belum,final,terkirim,manual'],
            'tab' => ['nullable', 'in:aktif,dipindahkan,hilang_tanpa_periksa'],
        ]);
        $tab = $v['tab'] ?? 'aktif';

        $sep = strtoupper(preg_replace('/\s+/', '', (string) ($v['sep'] ?? '')));
        $dari = filled($v['dari'] ?? null) ? Carbon::parse($v['dari'])->startOfDay() : null;
        $sampai = filled($v['sampai'] ?? null) ? Carbon::parse($v['sampai'])->endOfDay() : null;
        if ($dari && $sampai && $dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai->copy()->startOfDay(), $dari->copy()->endOfDay()];
        }
        $periksa = $v['periksa'] ?? '';
        $kesiapan = $v['kesiapan'] ?? '';
        $eklaim = $v['eklaim'] ?? '';

        // Filter No. SEP dan tanggal; status pemeriksaan ditambahkan terpisah supaya jumlah per status bisa dihitung
        $filtered = fn () => FtpFolder::query()
            ->when($sep !== '', fn ($w) => $w->where('no_sep', 'like', "%$sep%"))
            ->when($dari, fn ($w) => $w->where('files_modified_at', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('files_modified_at', '<=', $sampai))
            ->when($kesiapan === 'siap', fn ($w) => $w->where('rubric_kurang', 0))
            ->when($kesiapan === 'belum_siap', fn ($w) => $w->where('rubric_kurang', '>', 0))
            ->when($eklaim === 'belum', fn ($w) => $w->whereNull('eklaim_status')->whereNull('diajukan_manual_at'))
            ->when(in_array($eklaim, ['final', 'terkirim'], true), fn ($w) => $w->where('eklaim_status', $eklaim))
            ->when($eklaim === 'manual', fn ($w) => $w->whereNull('eklaim_status')->whereNotNull('diajukan_manual_at'));
        $base = fn () => $filtered()->moved($tab);
        $byStatus = fn ($w, string $p) => match ($p) {
            'sudah' => $w->where('analysis_status', 'selesai'),
            'belum' => $w->whereNull('analysis_status'),
            default => $w,
        };

        $folders = $byStatus($base(), $periksa)->when($tab !== 'aktif', fn ($w) => $w->orderByDesc('missing_at'))->orderByDesc('files_modified_at')->orderByDesc('id')->paginate(25)->withQueryString();
        // Dipindah tanpa diperiksa: salinan arsip otomatis tersedia s.d. tanggal musnah (stored_at tertua + auto_retention_months)
        $copies = $tab !== 'hilang_tanpa_periksa' ? collect() : FolderDocument::whereIn('ftp_folder_id', $folders->pluck('id'))->whereNotNull('stored_path')
            ->groupBy('ftp_folder_id')->selectRaw('ftp_folder_id, min(stored_at) as s')->pluck('s', 'ftp_folder_id')
            ->map(fn ($s) => Carbon::parse($s)->addMonths((int) config('casemix.archive.auto_retention_months', 3)));

        return view('folders.index', [
            'folders' => $folders,
            'copies' => $copies,
            'filters' => ['sep' => $sep, 'dari' => $dari?->toDateString(), 'sampai' => $sampai?->toDateString(), 'periksa' => $periksa, 'kesiapan' => $kesiapan, 'eklaim' => $eklaim],
            'tab' => $tab,
            'tabCounts' => collect(['aktif', 'dipindahkan', 'hilang_tanpa_periksa'])->mapWithKeys(fn ($t) => [$t => $filtered()->moved($t)->count()])->all(),
            'counts' => ['semua' => $base()->count(), 'sudah' => $byStatus($base(), 'sudah')->count(), 'belum' => $byStatus($base(), 'belum')->count()],
            'lastSync' => AppSetting::get(FolderSync::KEY_LAST),
            'bolehAksi' => $request->user()->can('folders.analyze'),
            'latar' => FtpFolder::withoutGlobalScopes()->whereNotNull('verifikasi_status')->whereIn('verifikasi_status', ['antre', 'berjalan', 'menjeda', 'dijeda'])
                ->selectRaw('verifikasi_status, count(*) as n')->groupBy('verifikasi_status')->pluck('n', 'verifikasi_status')->all(),
        ]);
    }

    /** Folder punya salinan arsip (mis. arsip otomatis saat sinkron), jadi bisa dianalisa walau sudah hilang dari FTP. */
    public static function hasCopy(FtpFolder $folder): bool
    {
        return FolderDocument::where('ftp_folder_id', $folder->id)->whereNotNull('stored_path')->exists();
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
        // Bagian Temuan: gabungan semua file, tanda "Sudah dicek" per kunci stabil
        $data['temuan'] = \App\Models\FolderTemuanCek::temuan($data['done'], $data['documents']);
        $data['dicek'] = \App\Models\FolderTemuanCek::where('folder_id', $folder->id)->pluck('temuan_key')->flip();
        if ($data['bpjs'] && $folder->no_sep && ! $request->ajax()) {
            Audit::log('vclaim.riwayat.buka', $folder, 'Membuka panel riwayat pelayanan peserta', ['folder_id' => $folder->id]);
        }

        // Dipanggil polling saat analisa selesai: hanya isi halaman, ditukar tanpa memuat ulang
        if ($request->ajax()) {
            return response()->view('folders.partials.detail', $data)->header('Vary', 'X-Requested-With');
        }

        return view('folders.show', $data);
    }

    public function analyze(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        abort_if($folder->missing_at && ! self::hasCopy($folder), 404);
        if ($folder->analyses()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }
        $this->start($folder, $request->user()->id, paksa: true); // tombol "Analisa ulang" selalu memanggil AI
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
        SynthesizeFolderAnalysis::dispatch($a->id, true);
        Audit::log('folder.ulang_rekomendasi', $folder, 'Meminta rekomendasi keseluruhan dibuat ulang (file tidak dibaca ulang)');

        return back()->with('ok', 'Rekomendasi keseluruhan sedang dibuat ulang.');
    }

    /** Baca ulang SEMUA file (tanpa memakai hasil lama), lalu periksa silang. */
    public function processAll(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        abort_if($folder->missing_at && ! self::hasCopy($folder), 404);
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
        if ($folder->missing_at && ! $document->stored_path && ! ReadFolderDocument::storedSibling($document)) {
            return back()->with('warn', 'File sudah tidak ada di FTP dan belum diarsipkan.');
        }
        if ($a->status?->isActive()) {
            return back()->with('warn', 'Folder ini sedang dianalisa.');
        }
        if ($a->documents()->whereKeyNot($document->id)->where('status', ReviewStatus::Gagal->value)->exists()) {
            return back()->with('warn', 'Ada file lain yang gagal dibaca. Gunakan tombol Lanjutkan dari dokumen yang gagal.');
        }
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }

        if ($err = app(VerifikasiLatar::class)->lanjut($folder, ulangDokumen: [$document->id])) {
            return back()->with('warn', $err);
        }
        Audit::log('folder.proses_file', $folder, 'Meminta file dibaca ulang: ' . $document->name);

        return back()->with('ok', 'File dibaca ulang. Hasil tampil saat selesai.');
    }

    /** Lanjutkan analisa yang berhenti: baca ulang dokumen gagal + dibatalkan, lalu periksa silang. Dokumen selesai tidak dibaca ulang. */
    public function resume(Request $request, FtpFolder $folder, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        abort_unless($a, 404);
        if ($blocked = $this->startBlocker($budget)) {
            return back()->with('warn', $blocked);
        }
        $n = $a->documents()->whereNot('status', ReviewStatus::Selesai->value)->count();
        if ($err = app(VerifikasiLatar::class)->lanjut($folder)) {
            return back()->with('warn', $err);
        }
        Audit::log('folder.analisa_lanjut', $folder, 'Melanjutkan analisa dari file yang belum selesai (' . $n . ' dokumen)');

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

    /** Tugaskan/lepas petugas folder (Koordinator). user_id kosong = lepas. Diaudit dengan nilai lama/baru. */
    /** Tanda manual "sudah diajukan" bila status E-Klaim belum terdeteksi. Tidak menulis ke E-Klaim. */
    public function tandaiDiajukan(Request $request, FtpFolder $folder)
    {
        if (! $folder->eklaim_status && ! $folder->diajukan_manual_at) {
            $folder->forceFill(['diajukan_manual_at' => now(), 'diajukan_manual_oleh' => $request->user()->id])->save();
            Audit::log('folder.diajukan_manual', $folder, 'Menandai berkas sudah diajukan (manual)');
        }

        return back()->with('status', 'Berkas ditandai sudah diajukan.');
    }

    public function batalDiajukan(FtpFolder $folder)
    {
        if ($folder->diajukan_manual_at) {
            $folder->forceFill(['diajukan_manual_at' => null, 'diajukan_manual_oleh' => null])->save();
            Audit::log('folder.diajukan_manual_batal', $folder, 'Membatalkan tanda sudah diajukan (manual)');
        }

        return back()->with('status', 'Tanda sudah diajukan dibatalkan.');
    }

    public function assign(Request $request, FtpFolder $folder)
    {
        $v = $request->validate(['user_id' => ['nullable', 'integer', 'exists:users,id']]);
        $lama = $folder->assigned_to;
        $baru = isset($v['user_id']) ? (int) $v['user_id'] : null;
        if ($lama === $baru) {
            return back()->with('ok', 'Penugasan tidak berubah.');
        }
        $folder->update(['assigned_to' => $baru, 'assigned_at' => $baru ? now() : null]);
        $nama = fn ($id) => $id ? (\App\Models\User::find($id)?->name ?? "#$id") : null;
        Audit::log($baru ? 'folder.tugaskan' : 'folder.lepas_tugas', $folder,
            $baru ? 'Menugaskan folder ke ' . $nama($baru) : 'Melepas penugasan folder dari ' . $nama($lama),
            ['perubahan' => ['assigned_to' => ['lama' => $lama, 'baru' => $baru], 'petugas' => ['lama' => $nama($lama), 'baru' => $nama($baru)]]]);

        return back()->with('ok', $baru ? 'Folder ditugaskan.' : 'Penugasan dilepas.');
    }

    /** Catat tindak lanjut saran kode ke DPJP. Tidak memengaruhi status Siap diajukan dan tidak menulis ke Enigma. */
    public function codeStatus(Request $request, FtpFolder $folder, CodeSuggestion $code)
    {
        abort_unless($code->analysis?->ftp_folder_id === $folder->id, 404);
        $v = $request->validate([
            'status' => ['required', 'in:' . implode(',', CodeSuggestion::STATUS)],
        ]);
        $lama = $code->status_tindak_lanjut;
        $code->update(['status_tindak_lanjut' => $v['status']]);
        Audit::log('folder.saran_kode_status', $folder, sprintf('Saran kode %s "%s": %s → %s', $code->code, $code->title, $lama ?? 'baru', $v['status']),
            ['saran_kode' => $code->id, 'kode' => $code->code, 'dari' => $lama, 'ke' => $v['status']]);

        return back()->with('ok', 'Tindak lanjut saran kode dicatat.');
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
                try {
                    ReadFolderDocument::archiveContents($doc, $contents);
                } catch (\Throwable $e) {
                    Log::warning('Salinan arsip gagal disimpan; dokumen tetap ditampilkan dari FTP', ['document_id' => $doc->id, 'error' => $e->getMessage()]);
                }
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

        return $budget->startBlocker('analisa');
    }

    /** Analisa ulang otomatis (mis. dokumen manual ditambah/dihapus). Null bila sedang berjalan atau AI tidak tersedia. */
    public static function autoAnalyze(FtpFolder $folder, int $userId): ?FolderAnalysis
    {
        $self = app(self::class);
        if ($folder->analyses()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()
            || $self->startBlocker(app(AiBudget::class))) {
            return null;
        }

        return $self->start($folder, $userId);
    }

    /** JSON ringkas untuk polling panel hasil: status analisa terakhir dan HTML hasil saat sudah selesai/gagal. */
    public function statusAnalisa(FtpFolder $folder, AiBudget $budget)
    {
        $a = $folder->analyses()->first();
        $status = $a?->status?->value;
        $active = in_array($status, [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value], true);

        return response()->json([
            'status' => $status,
            'active' => $active,
            'termasuk_manual' => (bool) $a?->termasuk_manual,
            'html' => $active || ! $a ? null : view('folders.partials.detail', $this->viewData($folder, $a, $this->startBlocker($budget)))->render(),
        ]);
    }

    /** @param bool $all true = semua file dibaca ulang; false = hanya file yang berubah */
    public function start(FtpFolder $folder, int $userId, bool $all = false, bool $paksa = false, ?string $queue = null): FolderAnalysis
    {
        $a = app(VerifikasiLatar::class)->mulai($folder, $userId, $all, $paksa, $queue);

        return $a;
    }

    private function viewData(FtpFolder $folder, ?FolderAnalysis $latest, ?string $blocker): array
    {
        $done = $latest?->status === ReviewStatus::Selesai ? $latest : $folder->analyses()->where('status', ReviewStatus::Selesai->value)->first();
        $saranOn = (bool) AppSetting::get('ai_condition_suggestions', false);
        $rubric = $folder->rubricItems()->get()->keyBy('kode');

        return [
            'folder' => $folder,
            'hasCopy' => $folder->missing_at && self::hasCopy($folder),
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
            'kodeOn' => $kodeOn = SettingController::codeSuggestionsOn(),
            'codes' => $kodeOn ? $this->latestCodes($folder) : collect(),
            'bpjs' => auth()->user()?->hasRole('koordinator') ? $this->bpjs($folder) : null,
            'manualFiles' => auth()->user()?->hasRole('koordinator') ? $folder->manualFiles()->with('uploader:id,name')->get() : collect(),
            // Acuan regulasi (Koordinator): dasar koding per kode; dasar sanggahan bila folder perlu tindak lanjut BPJS
            'dasarKoding' => auth()->user()?->hasRole('koordinator') ? app(DasarKoding::class)->untukFolder($folder) : collect(),
            'sanggahan' => auth()->user()?->hasRole('koordinator') && $folder->bpjs_tindak_lanjut ? app(DasarSanggahan::class)->untukFolder($folder) : null,
        ];
    }

    /** "Salin dasar koding" (Koordinator): teks disalin di peramban; di sini hanya dicatat. */
    public function salinDasarKoding(FtpFolder $folder, DasarKoding $dk)
    {
        $items = $dk->untukFolder($folder);
        Audit::log('regulasi.salin_dasar_koding', $folder, 'Menyalin dasar koding', ['kode' => $items->pluck('kode')->all(),
            'butir' => $items->flatMap(fn ($x) => $x['butir']->pluck('id'))->unique()->values()->all()]);

        return response()->json(['ok' => true]);
    }

    /** "Susun draf sanggahan" (Koordinator): hanya butir terkonfirmasi yang ditampilkan di panel dan dikirim dengan id. */
    public function drafSanggahan(Request $request, FtpFolder $folder, DasarSanggahan $ds, DrafSanggahan $draf)
    {
        abort_unless($folder->bpjs_tindak_lanjut, 404);
        $v = $request->validate(['butir' => ['nullable', 'array'], 'butir.*' => ['integer']]);
        $panel = $ds->untukFolder($folder);
        $hasil = $draf->susun($panel['semua'], $v['butir'] ?? [], ['status_klaim' => $folder->vclaimKlaim?->status_kategori,
            'jenis_rawat' => $folder->jenis_rawat, 'kode' => $panel['kode']], $folder->id);
        Audit::log('regulasi.draf_susun', $folder, 'Menyusun draf sanggahan', ['butir' => $hasil['butir']]);

        return response()->json($hasil);
    }

    public function unduhDraf(Request $request, FtpFolder $folder, DrafSanggahan $draf)
    {
        $v = $request->validate(['draf' => ['required', 'string', 'max:20000'], 'format' => ['required', 'in:docx,txt']]);
        Audit::log('regulasi.draf_unduh', $folder, 'Mengunduh draf sanggahan (' . $v['format'] . ')', ['format' => $v['format']]);

        return $draf->unduh($v['draf'], $v['format'], 'draf-sanggahan-' . Str::slug($folder->no_sep ?: (string) $folder->id));
    }

    private static function noKartu(FtpFolder $folder): ?string
    {
        return $folder->vclaimKlaim?->no_kartu ?: $folder->vclaimKunjungan?->no_kartu ?: null;
    }

    /** Data VClaim untuk panel folder: status klaim + riwayat 90 hari dengan tanda kemungkinan readmisi. */
    private function bpjs(FtpFolder $folder): array
    {
        if (! $folder->no_sep) {
            return ['tanpa_sep' => true];
        }
        $klaim = $folder->vclaimKlaim;
        $noKartu = self::noKartu($folder);

        return ['tanpa_sep' => false, 'klaim' => $klaim, 'no_kartu' => $noKartu,
            'histori' => $noKartu ? app(VclaimHistoriService::class)->simpanan($folder->no_sep) : null];
    }

    /** Tombol "Ambil riwayat pelayanan" (Koordinator): JSON, tanpa muat ulang dan tanpa antrean. */
    public function histori(Request $request, FtpFolder $folder, VclaimHistoriService $svc)
    {
        return $svc->jawab($request, $folder->no_sep, $folder, ['folder_id' => $folder->id, 'no_sep' => $folder->no_sep]);
    }

    /** Saran kode dari analisa terakhir yang menghasilkan saran kode. */
    private function latestCodes(FtpFolder $folder)
    {
        $id = CodeSuggestion::whereHas('analysis', fn ($q) => $q->where('ftp_folder_id', $folder->id))->max('folder_analysis_id');

        return $id ? CodeSuggestion::with('document')->where('folder_analysis_id', $id)->orderBy('id')->get() : collect();
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
