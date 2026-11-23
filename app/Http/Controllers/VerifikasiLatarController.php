<?php

namespace App\Http\Controllers;

use App\Models\AiProvider;
use App\Models\FtpFolder;
use App\Services\Ai\AiBudget;
use App\Services\Verification\VerifikasiLatar;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Verifikasi di latar belakang: status (polling), jeda/lanjut per berkas, verifikasi massal, jeda/lanjut semua. */
class VerifikasiLatarController extends Controller
{
    public function __construct(private VerifikasiLatar $latar) {}

    /** GET ?ids=1,2,3 — dipanggil tiap 3 detik HANYA selama ada berkas antre/berjalan/menjeda di halaman. */
    public function status(Request $request)
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->map(fn ($v) => (int) $v)->filter()->unique()->take(100);
        $boleh = (bool) $request->user()?->can('folders.analyze');
        $berkas = FtpFolder::whereIn('id', $ids)->get()->map(fn (FtpFolder $f) => [
            'id' => $f->id,
            'status' => $f->statusVerifikasi(),
            'aktif' => in_array($f->statusVerifikasi(), FtpFolder::VERIFIKASI_AKTIF, true),
            'selesai' => $f->verifikasi_file_selesai,
            'total' => $f->verifikasi_file_total,
            'file_aktif' => $f->verifikasi_file_aktif,
            'pesan' => $f->verifikasi_pesan,
            'html' => view('folders.partials.verifikasi-latar', ['f' => $f, 'bolehAksi' => $boleh])->render(),
        ])->values();

        return response()->json(['aktif' => $berkas->contains('aktif', true), 'berkas' => $berkas]);
    }

    public function jeda(Request $request, FtpFolder $folder)
    {
        if (! $this->latar->jeda($folder)) {
            return $this->balas($request, $folder, 'warn', 'Berkas ini tidak sedang diverifikasi.');
        }
        Audit::log('folder.verifikasi_jeda', $folder, 'Menjeda verifikasi berkas');

        return $this->balas($request, $folder, 'ok', 'Verifikasi dijeda. File yang sedang dibaca AI diselesaikan dulu.');
    }

    public function lanjut(Request $request, FtpFolder $folder)
    {
        if ($blocked = $this->blocker()) {
            return $this->balas($request, $folder, 'warn', $blocked);
        }
        if ($err = $this->latar->lanjut($folder)) {
            return $this->balas($request, $folder, 'warn', $err);
        }
        Audit::log('folder.verifikasi_lanjut', $folder, 'Melanjutkan verifikasi berkas dari file yang belum selesai');

        return $this->balas($request, $folder, 'ok', 'Verifikasi dilanjutkan.');
    }

    /** Tombol Jeda/Lanjutkan lewat fetch: kembalikan status + HTML terbaru supaya tombol berganti tanpa memuat ulang. */
    private function balas(Request $request, FtpFolder $folder, string $jenis, string $pesan)
    {
        if (! $request->expectsJson()) {
            return back()->with($jenis, $pesan);
        }
        $folder->refresh();

        return response()->json(['ok' => $jenis === 'ok', 'pesan' => $pesan, 'status' => $folder->statusVerifikasi(),
            'html' => view('folders.partials.verifikasi-latar', ['f' => $folder, 'bolehAksi' => true])->render()]);
    }

    /** POST JSON: jumlah berkas yang akan diproses/dilewati dan perkiraan biaya, untuk konfirmasi sebelum dispatch. */
    public function perkiraan(Request $request)
    {
        [$r] = $this->rencana($request);

        return response()->json([
            'diproses' => $r['mulai']->count() + $r['lanjut']->count(),
            'dilanjutkan' => $r['lanjut']->count(),
            'dilewati' => count($r['lewati']),
            'alasan_dilewati' => array_count_values($r['lewati']),
            'perkiraan_usd' => $r['perkiraan_usd'],
            'rata_usd' => $r['rata_usd'],
            'sisa_usd' => $r['sisa_usd'],
            'boleh' => $r['pesan'] === null && ! $this->blocker(),
            'pesan' => $r['pesan'] ?? $this->blocker(),
        ]);
    }

    public function massal(Request $request)
    {
        [$r, $ulang] = $this->rencana($request);
        if ($blocked = $r['pesan'] ?? $this->blocker()) {
            return back()->with('warn', $blocked);
        }
        $antrean = VerifikasiLatar::antreanMassal();
        $user = $request->user()->id;
        foreach ($r['mulai'] as $f) {
            app(FolderController::class)->start($f, $user, paksa: $ulang && $f->analysis_status === 'selesai', queue: $antrean);
        }
        $gagal = 0;
        foreach ($r['lanjut'] as $f) {
            $gagal += $this->latar->lanjut($f, $antrean) ? 1 : 0;
        }
        $n = $r['mulai']->count() + $r['lanjut']->count() - $gagal;
        Audit::log('folder.verifikasi_massal', null, sprintf('Verifikasi massal: %d berkas diantrekan, %d dilewati, perkiraan US$%s%s',
            $n, count($r['lewati']) + $gagal, number_format($r['perkiraan_usd'], 2), $ulang ? ' (analisa ulang)' : ''));

        return back()->with('ok', sprintf('%d berkas diantrekan untuk diverifikasi di latar belakang%s.', $n,
            count($r['lewati']) + $gagal ? ', ' . (count($r['lewati']) + $gagal) . ' dilewati' : ''));
    }

    public function jedaSemua(Request $request)
    {
        $n = FtpFolder::whereIn('verifikasi_status', ['antre', 'berjalan'])->get()->filter(fn (FtpFolder $f) => $this->latar->jeda($f))->count();
        Audit::log('folder.verifikasi_jeda_semua', null, "Menjeda semua verifikasi ($n berkas)");

        return back()->with('ok', "$n berkas dijeda. File yang sedang dibaca AI diselesaikan dulu.");
    }

    public function lanjutSemua(Request $request)
    {
        if ($blocked = $this->blocker()) {
            return back()->with('warn', $blocked);
        }
        $folders = FtpFolder::whereIn('verifikasi_status', ['dijeda', 'berjalan', 'menjeda'])->orderBy('id')->get()
            ->filter(fn (FtpFolder $f) => in_array($f->statusVerifikasi(), ['dijeda', 'terhenti'], true))->take(VerifikasiLatar::MAKS_MASSAL);
        $r = $this->latar->rencana($folders, false);
        if ($r['pesan']) {
            return back()->with('warn', $r['pesan']);
        }
        $n = $r['lanjut']->filter(fn (FtpFolder $f) => $this->latar->lanjut($f, VerifikasiLatar::antreanMassal()) === null)->count();
        Audit::log('folder.verifikasi_lanjut_semua', null, "Melanjutkan semua verifikasi yang dijeda ($n berkas)");

        return back()->with('ok', "$n berkas dilanjutkan.");
    }

    /** @return array{0: array, 1: bool} */
    private function rencana(Request $request): array
    {
        $v = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . VerifikasiLatar::MAKS_MASSAL],
            'ids.*' => ['integer'],
            'ulang' => ['nullable', 'boolean'],
        ], ['ids.max' => 'Maksimal ' . VerifikasiLatar::MAKS_MASSAL . ' berkas per aksi.', 'ids.required' => 'Pilih minimal satu berkas.']);
        $ulang = (bool) ($v['ulang'] ?? false);

        return [$this->latar->rencana(FtpFolder::whereIn('id', $v['ids'])->orderBy('id')->get(), $ulang), $ulang];
    }

    private function blocker(): ?string
    {
        if (! AiProvider::default()) {
            return 'Belum ada penyedia AI yang aktif. Minta Admin mengatur Penyedia AI.';
        }

        return app(AiBudget::class)->startBlocker('analisa');
    }
}
