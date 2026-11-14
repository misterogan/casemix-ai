<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportItem;
use App\Services\Import\DocumentGuesser;
use App\Services\Import\ImportCommitter;
use App\Services\Import\SimrsFtp;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Impor dari FTP SIMRS per folder pasien (pola NoRM_NAMA_NoSEP).
 * Identitas diambil dari nama folder, jenis dokumen dari nama file. Tidak memakai AI.
 */
class ImportController extends Controller
{
    public function index(SimrsFtp $ftp)
    {
        $folders = collect();
        $error = null;
        if (! $ftp->configured()) {
            $error = 'Koneksi FTP SIMRS belum diatur. Minta Admin mengisi SIMRS_FTP_* di .env.';
        } else {
            try {
                $folders = $this->withStatus($ftp->listPatientFolders());
            } catch (\Throwable $e) {
                $error = 'Tidak bisa membaca FTP SIMRS: ' . $e->getMessage();
            }
        }

        return view('imports.index', [
            'folders' => $folders,
            'error' => $error,
            'max' => config('casemix.ftp.max_files_per_import'),
            'root' => config('casemix.ftp.path'),
            'batches' => ImportBatch::with('creator')->withCount('items')->latest('id')->paginate(15),
        ]);
    }

    public function store(Request $request, SimrsFtp $ftp)
    {
        $request->validate(['folders' => ['required', 'array', 'min:1'], 'folders.*' => ['string']],
            ['folders.required' => 'Pilih minimal satu folder pasien.']);

        $all = $this->withStatus($ftp->listPatientFolders())->keyBy('path');
        $max = (int) config('casemix.ftp.max_files_per_import');
        $picked = [];
        $count = 0;

        foreach ($request->folders as $path) {
            $f = $all->get($path);
            if (! $f || $f['new_files'] === []) {
                continue;
            }
            $n = count($f['new_files']);
            if ($count > 0 && $count + $n > $max) {
                break; // satu folder tidak dipecah ke dua kali impor
            }
            $picked[] = $f;
            $count += $n;
        }

        if ($picked === []) {
            return back()->with('warn', 'Tidak ada file baru di folder yang dipilih.');
        }

        $batch = ImportBatch::create([
            'source' => 'ftp',
            'remote_dir' => config('casemix.ftp.path'),
            'total' => $count,
            'created_by' => $request->user()->id,
        ]);

        foreach ($picked as $f) {
            $groupKey = $f['no_sep'] ? 'sep:' . $f['no_sep']
                : ($f['no_rm'] ? 'rm:' . ltrim($f['no_rm'], '0') : 'folder:' . md5($f['path']));
            foreach ($f['new_files'] as $file) {
                $batch->items()->create([
                    'remote_path' => $file['path'],
                    'remote_size' => $file['size'],
                    'original_name' => $file['name'],
                    'detected_type' => DocumentGuesser::type($file['name'])->value,
                    'detected' => [
                        'nama' => $f['nama'],
                        'no_rm' => $f['no_rm'],
                        'no_sep' => $f['no_sep'],
                        'tanggal' => null,
                        'jenis_rawat' => $f['jenis_rawat'],
                        'folder' => $f['folder'],
                        'keyakinan' => $f['dikenali'] ? 'tinggi' : 'rendah',
                    ],
                    'group_key' => $groupKey,
                ]);
            }
        }

        ProcessImportBatch::dispatch($batch->id);
        Audit::log('impor.mulai', $batch, 'Mengimpor ' . count($picked) . " folder pasien ($count file) dari FTP SIMRS");

        $sisa = count($request->folders) - count($picked);

        return redirect()->route('imports.show', $batch)->with($sisa > 0 ? 'warn' : 'ok', $sisa > 0
            ? count($picked) . " folder diimpor. $sisa folder lain melewati batas $max file per impor; impor lagi setelah ini selesai."
            : count($picked) . ' folder sedang diunduh.');
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
            'groups.*.tanggal_masuk' => ['nullable', 'date'],
            'lanjutan' => ['nullable', Rule::in([ImportCommitter::NEXT_FILL, ImportCommitter::NEXT_REVIEW, ImportCommitter::NEXT_NONE])],
        ]);
        $next = $data['lanjutan'] ?? ImportCommitter::NEXT_NONE;

        $groups = collect($data['groups'] ?? [])->map(fn ($g) => array_filter($g, fn ($v) => $v !== null && $v !== ''))->all();
        $result = $committer->commit($batch, $data['items'] ?? [], $groups, $request->user()->id, $next);
        $ids = $result['claims'];
        Audit::log('impor.simpan', $batch, 'Menyimpan hasil impor menjadi ' . count($ids) . ' berkas'
            . ($result['blocked'] ? ', ' . count($result['blocked']) . ' kelompok tertahan' : ''));

        if ($result['blocked']) {
            $daftar = collect($result['blocked'])->map(fn ($b) => "SEP {$b['no_sep']} ({$b['files']} file)")->implode(', ');

            return redirect()->route('imports.show', $batch)->with('warn', count($ids) . ' berkas disimpan. '
                . "Dokumen untuk $daftar tertahan karena berkasnya sudah disetujui. "
                . 'Minta Koordinator membuka kembali berkas tersebut, lalu simpan impor ini lagi.');
        }

        if ($result['ai_skipped']) {
            return redirect()->route('claims.index')->with('warn', count($ids) . ' berkas disimpan. '
                . $result['ai_skipped'] . ' berkas tidak diproses AI: ' . $result['ai_skipped_reason']);
        }

        return redirect()->route('claims.index')->with('ok', count($ids) . ' berkas disimpan' . match ($next) {
            ImportCommitter::NEXT_FILL => '. Dokumen sedang dibaca untuk usulan isian; buka tiap berkas untuk mengonfirmasi.',
            ImportCommitter::NEXT_REVIEW => ' dan sedang diperiksa AI.',
            default => '.',
        });
    }

    /** Tandai file yang sudah pernah diimpor (berdasarkan path + ukuran) dan status tiap folder. */
    private function withStatus(Collection $folders): Collection
    {
        $paths = $folders->flatMap(fn ($f) => array_column($f['files'], 'path'))->all();
        $seen = $paths === [] ? collect() : ImportItem::query()
            ->whereIn('remote_path', $paths)
            ->whereNotIn('status', ['gagal', 'dilewati'])
            ->get(['remote_path', 'remote_size'])
            ->map(fn ($i) => $i->remote_path . '|' . $i->remote_size)->flip();

        return $folders->map(function (array $f) use ($seen) {
            $f['new_files'] = array_values(array_filter($f['files'], fn ($x) => ! $seen->has($x['path'] . '|' . $x['size'])));
            $f['status'] = match (true) {
                $f['files'] === [] => 'kosong',
                $f['new_files'] === [] => 'sudah',
                count($f['new_files']) === count($f['files']) => 'baru',
                default => 'tambahan',
            };

            return $f;
        });
    }
}
