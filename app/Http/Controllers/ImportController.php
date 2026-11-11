<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Jobs\ProcessImportBatch;
use App\Models\AiProvider;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Services\Import\ImportCommitter;
use App\Services\Import\SimrsFtp;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImportController extends Controller
{
    public function index(SimrsFtp $ftp)
    {
        $pending = collect();
        $error = null;
        if ($ftp->configured()) {
            try {
                $pending = $this->newFiles($ftp);
            } catch (\Throwable $e) {
                $error = 'Tidak bisa membaca FTP SIMRS: ' . $e->getMessage();
            }
        } else {
            $error = 'Koneksi FTP SIMRS belum diatur. Minta Admin mengisi SIMRS_FTP_* di .env.';
        }

        return view('imports.index', [
            'pending' => $pending,
            'error' => $error,
            'max' => config('casemix.ftp.max_files_per_import'),
            'folder' => config('casemix.ftp.path'),
            'batches' => ImportBatch::with('creator')->withCount('items')->latest('id')->paginate(15),
        ]);
    }

    public function store(Request $request, SimrsFtp $ftp)
    {
        if (! AiProvider::default()) {
            return back()->with('warn', 'Belum ada penyedia AI yang aktif untuk memilah dokumen.');
        }
        $files = $this->newFiles($ftp)->take(config('casemix.ftp.max_files_per_import'));
        if ($files->isEmpty()) {
            return back()->with('warn', 'Tidak ada file baru di folder FTP.');
        }

        $batch = ImportBatch::create([
            'source' => 'ftp',
            'remote_dir' => config('casemix.ftp.path'),
            'total' => $files->count(),
            'created_by' => $request->user()->id,
        ]);
        foreach ($files as $f) {
            $batch->items()->create(['remote_path' => $f['path'], 'remote_size' => $f['size'], 'original_name' => $f['name']]);
        }
        ProcessImportBatch::dispatch($batch->id);
        Audit::log('impor.mulai', $batch, "Mengimpor {$files->count()} file dari FTP SIMRS");

        return redirect()->route('imports.show', $batch);
    }

    public function show(ImportBatch $batch)
    {
        $items = $batch->items()->orderBy('group_key')->orderBy('id')->get();

        return view('imports.show', [
            'batch' => $batch,
            'groups' => $items->where('status', '!=', 'gagal')->groupBy('group_key'),
            'failed' => $items->where('status', 'gagal'),
            'types' => DocumentType::cases(),
        ]);
    }

    public function commit(Request $request, ImportBatch $batch, ImportCommitter $committer)
    {
        abort_unless($batch->status === 'siap_ditinjau', 409, 'Impor ini tidak dalam tahap peninjauan.');

        $data = $request->validate([
            'items' => ['array'],
            'items.*.type' => ['required', Rule::enum(DocumentType::class)],
            'items.*.group_key' => ['required', 'string', 'max:255'],
            'items.*.skip' => ['nullable', 'boolean'],
            'groups' => ['array'],
            'groups.*.nama' => ['nullable', 'string', 'max:150'],
            'groups.*.no_rm' => ['nullable', 'string', 'max:30'],
            'groups.*.no_sep' => ['nullable', 'string', 'max:30'],
            'groups.*.jenis_rawat' => ['nullable', 'in:inap,jalan'],
            'groups.*.dpjp' => ['nullable', 'string', 'max:150'],
        ]);

        $groups = collect($data['groups'] ?? [])->map(fn ($g) => array_filter($g, fn ($v) => $v !== null && $v !== ''))->all();
        $ids = $committer->commit($batch, $data['items'] ?? [], $groups, $request->user()->id, $request->boolean('periksa'));
        Audit::log('impor.simpan', $batch, 'Menyimpan hasil impor menjadi ' . count($ids) . ' berkas');

        return redirect()->route('claims.index')->with('ok', count($ids) . ' berkas disimpan'
            . ($request->boolean('periksa') ? ' dan sedang diperiksa AI.' : '.'));
    }

    /** File di folder FTP yang belum pernah diimpor (berdasarkan path + ukuran). */
    private function newFiles(SimrsFtp $ftp)
    {
        $files = $ftp->listFiles(config('casemix.ftp.path'));
        $seen = ImportItem::query()->whereIn('remote_path', $files->pluck('path'))
            ->whereNotIn('status', ['gagal'])
            ->get(['remote_path', 'remote_size'])
            ->map(fn ($i) => $i->remote_path . '|' . $i->remote_size)->flip();

        return $files->reject(fn ($f) => $seen->has($f['path'] . '|' . $f['size']))->values();
    }
}
