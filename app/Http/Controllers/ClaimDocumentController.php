<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Models\Claim;
use App\Models\ClaimDocument;
use App\Services\Import\SimrsFtp;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClaimDocumentController extends Controller
{
    public function store(Request $request, Claim $claim)
    {
        Gate::authorize('update', $claim);
        $request->validate([
            'type' => ['required', Rule::enum(DocumentType::class)],
            'files' => ['required', 'array', 'max:20'],
            'files.*' => ['file', 'mimes:' . implode(',', config('casemix.upload.mimes')), 'max:' . config('casemix.upload.max_kb')],
        ], ['files.required' => 'Pilih minimal satu file.', 'files.*.mimes' => 'Format yang diterima: PDF, JPG, PNG, WebP.']);

        $disk = Storage::disk(config('casemix.storage_disk'));
        $names = [];
        foreach ($request->file('files') as $file) {
            $sha = hash_file('sha256', $file->getRealPath());
            if ($claim->documents()->where('sha256', $sha)->exists()) {
                continue; // file yang sama persis tidak diunggah dua kali
            }
            $path = $file->storeAs("claims/{$claim->id}", Str::ulid() . '.' . ($file->guessExtension() ?: Str::lower($file->getClientOriginalExtension()) ?: 'bin'), config('casemix.storage_disk'));
            $claim->documents()->create([
                'type' => $request->type,
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
                'mime' => $disk->mimeType($path) ?: $file->getMimeType(),
                'size' => $file->getSize(),
                'sha256' => $sha,
                'source' => 'unggah',
                'uploaded_by' => $request->user()->id,
            ]);
            $names[] = $file->getClientOriginalName();
        }

        if (! $names) {
            return back()->with('warn', 'File yang sama sudah ada di berkas ini.');
        }

        $label = DocumentType::from($request->type)->label();
        $claim->markContentChanged();
        $claim->refresh()->refreshStatus();
        Audit::log('dokumen.unggah', $claim, "Mengunggah $label: " . implode(', ', $names));

        return back()->with('ok', count($names) . " file $label diunggah.");
    }

    public function show(Claim $claim, ClaimDocument $document)
    {
        Gate::authorize('view', $claim);
        Audit::log('dokumen.lihat', $claim, "Membuka {$document->type->label()}: {$document->original_name}");

        // Hanya PDF/gambar yang ditampilkan di browser. Jenis lain diunduh sebagai file biner,
        // supaya file HTML/SVG yang menyamar sebagai .pdf tidak bisa berjalan di halaman aplikasi.
        $safe = in_array($document->mime, config('casemix.upload.content_types'), true);
        $name = str_replace(['"', "\r", "\n"], '', $document->original_name);

        return response()->file($document->absolutePath(), [
            'Content-Type' => $safe ? $document->mime : 'application/octet-stream',
            'Content-Disposition' => ($safe ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Koreksi jenis dokumen. */
    public function update(Request $request, Claim $claim, ClaimDocument $document)
    {
        Gate::authorize('update', $claim);
        $request->validate(['type' => ['required', Rule::enum(DocumentType::class)]]);
        $old = $document->type->label();
        $document->update(['type' => $request->type]);
        $claim->markContentChanged();
        $claim->refresh()->refreshStatus();
        Audit::log('dokumen.ubah_jenis', $claim, "Mengubah jenis {$document->original_name} dari $old menjadi {$document->type->label()}");

        return back()->with('ok', 'Jenis dokumen diperbarui.');
    }

    public function destroy(Request $request, Claim $claim, ClaimDocument $document)
    {
        Gate::authorize('update', $claim);
        // Soft delete: file dan barisnya tetap disimpan sebagai bukti audit, hanya disembunyikan dari berkas.
        $document->forceFill(['deleted_by' => $request->user()->id])->saveQuietly();
        $document->delete();
        $claim->markContentChanged();
        $claim->refresh()->refreshStatus();
        Audit::log('dokumen.hapus', $claim, "Menghapus {$document->type->label()}: {$document->original_name}");

        return back()->with('ok', 'Dokumen dihapus.');
    }

    /** Daftar file riwayat pasien di FTP yang cocok dengan No. RM berkas ini. */
    public function history(Claim $claim, SimrsFtp $ftp)
    {
        Gate::authorize('update', $claim);
        try {
            $files = $ftp->findPatientHistory($claim->no_rm);
            $error = null;
        } catch (\Throwable $e) {
            $files = collect();
            $error = 'Tidak bisa membaca FTP: ' . $e->getMessage();
        }
        $attached = $claim->documents()->whereNotNull('remote_path')->pluck('remote_path')->all();

        return view('claims.history', compact('claim', 'files', 'error', 'attached'));
    }

    public function attachHistory(Request $request, Claim $claim, SimrsFtp $ftp)
    {
        Gate::authorize('update', $claim);
        $request->validate(['paths' => ['required', 'array', 'max:20'], 'paths.*' => ['string']]);

        // Hanya izinkan path yang benar-benar hasil pencarian No. RM ini.
        $allowed = $ftp->findPatientHistory($claim->no_rm)->keyBy('path');
        $disk = Storage::disk(config('casemix.storage_disk'));
        $count = 0;
        $rejected = [];

        foreach ($request->paths as $p) {
            if (! $allowed->has($p)) {
                continue;
            }
            $ext = Str::lower(pathinfo($p, PATHINFO_EXTENSION));
            $local = $ftp->download($p, "claims/{$claim->id}/" . Str::ulid() . ".$ext");
            $mime = $disk->mimeType($local) ?: 'application/octet-stream';
            if (! in_array($mime, config('casemix.upload.content_types'), true)) {
                $disk->delete($local);
                $rejected[] = basename($p);

                continue;
            }
            $claim->documents()->create([
                'type' => DocumentType::Tambahan->value,
                'original_name' => basename($p),
                'path' => $local,
                'mime' => $mime,
                'size' => $disk->size($local),
                'sha256' => hash_file('sha256', $disk->path($local)),
                'source' => 'riwayat',
                'remote_path' => $p,
                'uploaded_by' => $request->user()->id,
            ]);
            $count++;
        }

        if ($count) {
            $claim->markContentChanged();
            $claim->refresh()->refreshStatus();
            Audit::log('dokumen.riwayat', $claim, "Melampirkan $count file riwayat pasien dari FTP");
        }

        $msg = "$count file riwayat pasien dilampirkan sebagai dokumen tambahan.";
        if ($rejected) {
            return redirect()->route('claims.show', $claim)->with('warn', $msg . ' Ditolak karena isinya bukan PDF/gambar: ' . implode(', ', $rejected) . '.');
        }

        return redirect()->route('claims.show', $claim)->with('ok', $msg);
    }
}
