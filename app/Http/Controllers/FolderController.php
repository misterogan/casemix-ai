<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\RunFolderAnalysis;
use App\Models\AiProvider;
use App\Models\AppSetting;
use App\Models\FolderAnalysis;
use App\Models\FtpFolder;
use App\Services\Ai\AiBudget;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderSync;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Verifikasi berkas langsung dari folder FTP DHealth: tanpa pengisian, hanya rekomendasi AI. */
class FolderController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $state = $request->query('status');
        $state = array_key_exists((string) $state, FtpFolder::STATUSES) ? $state : null;

        $folders = FtpFolder::query()
            ->whereNull('missing_at')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nama_pasien', 'like', "%$q%")
                ->orWhere('no_rm', 'like', "%$q%")->orWhere('no_sep', 'like', "%$q%")->orWhere('name', 'like', "%$q%")))
            ->state($state)
            ->orderByDesc('files_modified_at')->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('folders.index', [
            'folders' => $folders,
            'q' => $q,
            'state' => $state,
            'counts' => collect(FtpFolder::STATUSES)->map(fn ($l, $k) => FtpFolder::whereNull('missing_at')->state($k)->count()),
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

        return view('folders.show', [
            'folder' => $folder,
            'latest' => $latest,
            'done' => $done,
            'changed' => $done && $done->fingerprint !== $folder->fingerprint,
            'blocker' => $blocker,
            'linkable' => collect($folder->files)->pluck('name')->flip(),
        ]);
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

        return response()->json(['status' => $a?->status?->value, 'active' => (bool) $a?->status?->isActive()]);
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
        RunFolderAnalysis::dispatch($a->id);

        return $a;
    }
}
