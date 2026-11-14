<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\ReadFolderDocument;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\SynthesizeFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use App\Services\Verification\FolderSync;
use App\Support\Audit;
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
        ]);

        $sep = strtoupper(preg_replace('/\s+/', '', (string) ($v['sep'] ?? '')));
        $dari = filled($v['dari'] ?? null) ? Carbon::parse($v['dari'])->startOfDay() : null;
        $sampai = filled($v['sampai'] ?? null) ? Carbon::parse($v['sampai'])->endOfDay() : null;
        if ($dari && $sampai && $dari->gt($sampai)) {
            [$dari, $sampai] = [$sampai->copy()->startOfDay(), $dari->copy()->endOfDay()];
        }
        $periksa = $v['periksa'] ?? '';

        // Filter No. SEP dan tanggal; status pemeriksaan ditambahkan terpisah supaya jumlah per status bisa dihitung
        $base = fn () => FtpFolder::query()
            ->whereNull('missing_at')
            ->when($sep !== '', fn ($w) => $w->where('no_sep', 'like', "%$sep%"))
            ->when($dari, fn ($w) => $w->where('files_modified_at', '>=', $dari))
            ->when($sampai, fn ($w) => $w->where('files_modified_at', '<=', $sampai));
        $byStatus = fn ($w, string $p) => match ($p) {
            'sudah' => $w->where('analysis_status', 'selesai'),
            'belum' => $w->whereNull('analysis_status'),
            default => $w,
        };

        return view('folders.index', [
            'folders' => $byStatus($base(), $periksa)->orderByDesc('files_modified_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => ['sep' => $sep, 'dari' => $dari?->toDateString(), 'sampai' => $sampai?->toDateString(), 'periksa' => $periksa],
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

        $done = $latest?->status === ReviewStatus::Selesai ? $latest : $folder->analyses()->where('status', ReviewStatus::Selesai->value)->first();

        $data = [
            'folder' => $folder,
            'latest' => $latest,
            'done' => $done,
            'changed' => $done && $done->fingerprint !== $folder->fingerprint,
            'blocker' => $blocker,
            'documents' => $latest ? $latest->documents()->get() : collect(),
            'linkable' => collect($folder->files)->pluck('name')->flip(),
        ];

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

    public function status(FtpFolder $folder)
    {
        $a = $folder->analyses()->first();
        $docs = $a ? $a->documents()->get(['id', 'status']) : collect();

        return response()->json([
            'active' => (bool) $a?->status?->isActive(),
            'done' => $docs->reject(fn (FolderDocument $d) => $d->status->isActive())->count(),
            'total' => $docs->count(),
        ]);
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

    /** Buka satu dokumen langsung dari FTP (tidak disimpan). Dipilih lewat nomor urut, bukan path, supaya tidak bisa diarahkan ke file lain. */
    public function file(FtpFolder $folder, int $index, SimrsFtp $ftp)
    {
        $f = $folder->fileAt($index);
        abort_unless($f, 404);

        $disk = Storage::disk(config('casemix.storage_disk'));
        $local = $ftp->download($f['path'], 'tmp/lihat/' . Str::ulid() . '.' . Str::lower(pathinfo($f['name'], PATHINFO_EXTENSION)));
        $mime = $disk->mimeType($local) ?: 'application/octet-stream';
        $safe = in_array($mime, config('casemix.upload.content_types'), true);
        Audit::log('folder.buka_dokumen', $folder, 'Membuka ' . $f['name']);

        return response()->file($disk->path($local), [
            'Content-Type' => $safe ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($safe ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $f['name']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    private function startBlocker(AiBudget $budget): ?string
    {
        if (! AiProvider::default()) {
            return 'Belum ada penyedia AI yang aktif. Minta Admin mengatur Penyedia AI.';
        }

        return $budget->startBlocker();
    }

    private function start(FtpFolder $folder, int $userId): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $folder->id, 'status' => ReviewStatus::Antre, 'requested_by' => $userId]);
        $folder->update(['analysis_status' => 'antre', 'last_analysis_id' => $a->id]);
        app(FolderAnalyzer::class)->prepare($a); // daftar dokumen langsung tampil sebagai "menunggu"
        RunFolderAnalysis::dispatch($a->id);

        return $a;
    }
}
