<?php

namespace App\Http\Controllers;

use App\Models\FtpFolder;
use App\Models\ManualFile;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Services\Manual\ManualStorage;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/** Unggah dokumen/gambar manual: dianalisa dengan pipeline folder yang sama, hasil di folder_analyses. */
class ManualUploadController extends Controller
{
    public function create()
    {
        return view('unggah.index', ['jenis' => ManualFile::JENIS]);
    }

    /** Cek SEP: folder yang sudah ada dan data VClaim untuk isi otomatis. */
    public function lookup(Request $request)
    {
        $sep = trim((string) $request->query('no_sep'));
        abort_unless(strlen($sep) === 19, 422);

        return response()->json(['folder' => $this->existing($sep), 'vclaim' => $this->vclaim($sep)]);
    }

    /** Unggah satu file (XHR). Folder dibuat saat file pertama, atau memakai folder_id yang dikirim. */
    public function store(Request $request, ManualStorage $storage)
    {
        $v = $request->validate([
            'file' => ['required', 'file'],
            'folder_id' => ['nullable', 'integer', 'exists:ftp_folders,id'],
            'no_sep' => ['nullable', 'string', 'size:19'],
            'no_rm' => ['nullable', 'required_without_all:no_sep,folder_id', 'string', 'max:30'],
            'jenis_rawat' => ['nullable', 'required_without:folder_id', 'in:inap,jalan'],
            'tgl_pulang' => ['nullable', 'date'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'jenis_dokumen' => ['nullable', 'in:' . implode(',', array_keys(ManualFile::JENIS))],
        ], [
            'no_rm.required_without_all' => 'No. RM wajib diisi bila No. SEP kosong.',
            'no_sep.size' => 'No. SEP harus 19 karakter.',
        ]);
        $userId = $request->user()->id;
        $folder = ! empty($v['folder_id']) ? FtpFolder::findOrFail($v['folder_id']) : null;

        try {
            $folder ??= $this->folderFor($v, $userId);
            $m = $storage->store($request->file('file'), $folder, $v['jenis_dokumen'] ?? null, $userId);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'folder_id' => $folder?->id], 422);
        }
        $baru = $folder->isManual();
        Audit::log($baru ? 'manual.unggah' : 'manual.tambah', $folder,
            ($baru ? 'Mengunggah dokumen manual' : 'Menambah dokumen manual ke folder FTP') . " ({$m->mime}, " . number_format($m->ukuran / 1024, 0) . ' KB)',
            ['folder_id' => $folder->id, 'manual_file_id' => $m->id]);

        return response()->json([
            'folder_id' => $folder->id,
            'file' => ['id' => $m->id, 'ukuran' => $m->ukuran, 'mime' => $m->mime],
        ], 201);
    }

    /** Semua file selesai terunggah: mulai analisa, lalu arahkan ke halaman folder. */
    public function analyze(Request $request, FtpFolder $folder)
    {
        $a = FolderController::autoAnalyze($folder, $request->user()->id);
        Audit::log('manual.analisa', $folder, $a ? 'Analisa dimulai setelah unggah dokumen manual' : 'Unggah dokumen manual selesai; analisa belum dapat dimulai',
            ['folder_id' => $folder->id]);

        return response()->json(['redirect' => route('folders.show', $folder), 'analisa' => (bool) $a]);
    }

    /** Lihat file manual (inline, nosniff); hanya lewat route ber-auth. */
    public function show(FtpFolder $folder, ManualFile $file)
    {
        abort_unless($file->ftp_folder_id === $folder->id, 404);
        $contents = ManualStorage::contents($file);
        abort_if($contents === null, 404);
        Audit::log('manual.lihat', $folder, 'Membuka dokumen manual #' . $file->id, ['manual_file_id' => $file->id]);

        return response($contents, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => 'inline; filename="dokumen-' . $file->id . '.' . ($file->mime === 'application/pdf' ? 'pdf' : 'jpg') . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Hapus (soft delete) dengan alasan wajib; memicu analisa ulang. */
    public function destroy(Request $request, FtpFolder $folder, ManualFile $file)
    {
        abort_unless($file->ftp_folder_id === $folder->id, 404);
        $v = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']], ['alasan.required' => 'Alasan hapus wajib diisi.']);
        $file->update(['alasan_hapus' => $v['alasan']]);
        $file->delete();
        Audit::log('manual.hapus', $folder, 'Menghapus dokumen manual #' . $file->id . ': ' . $v['alasan'], ['manual_file_id' => $file->id, 'alasan' => $v['alasan']]);
        $a = FolderController::autoAnalyze($folder, $request->user()->id);

        return back()->with('ok', 'Dokumen dihapus.' . ($a ? ' Analisa ulang dimulai.' : ''));
    }

    private function existing(string $sep): ?array
    {
        $f = FtpFolder::where('no_sep', $sep)->orderBy('id')->first();

        return $f ? ['id' => $f->id, 'nama' => $f->name, 'url' => route('folders.show', $f), 'sumber' => $f->sumber] : null;
    }

    private function vclaim(string $sep): ?array
    {
        $k = VclaimKlaim::where('no_sep', $sep)->first();
        $u = VclaimKunjungan::where('no_sep', $sep)->first();
        if (! $k && ! $u) {
            return null;
        }
        $jns = $k?->jns_pelayanan ?? $u?->jns_pelayanan;
        $pulang = $k?->tgl_pulang ?? $u?->tgl_plg_sep;

        return [
            'no_rm' => $k?->no_mr,
            'jenis_rawat' => $jns === null ? null : (($jns === '1' || str_contains(Str::lower($jns), 'inap')) ? 'inap' : 'jalan'),
            'tgl_pulang' => $pulang ? Carbon::parse($pulang)->toDateString() : null,
            'nama' => $k?->nama ?? $u?->nama,
        ];
    }

    /** Folder untuk unggahan baru: SEP yang sudah ada dipakai ulang (tidak membuat folder baru). */
    private function folderFor(array $v, int $userId): FtpFolder
    {
        $sep = $v['no_sep'] ?? null;
        if ($sep && ($f = FtpFolder::where('no_sep', $sep)->orderBy('id')->first())) {
            return $f;
        }
        $vc = $sep ? ($this->vclaim($sep) ?? []) : [];
        $rm = ($vc['no_rm'] ?? null) ?: ($v['no_rm'] ?? null);

        return FtpFolder::create([
            'path' => 'manual/' . Str::uuid(),
            'name' => $sep ? "Manual $sep" : 'Manual RM ' . $rm,
            'no_rm' => $rm,
            'nama_pasien' => $vc['nama'] ?? null,
            'no_sep' => $sep,
            'jenis_rawat' => ($vc['jenis_rawat'] ?? null) ?: ($v['jenis_rawat'] ?? null),
            'tgl_pulang' => ($vc['tgl_pulang'] ?? null) ?: ($v['tgl_pulang'] ?? null),
            'catatan' => $v['catatan'] ?? null,
            'files' => [],
            'file_count' => 0,
            'fingerprint' => FtpFolder::fingerprintOf([]),
            'files_modified_at' => now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'sumber' => 'manual',
            'dibuat_oleh' => $userId,
        ]);
    }
}
