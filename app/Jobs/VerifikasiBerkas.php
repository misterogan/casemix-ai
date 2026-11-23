<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FtpFolder;
use App\Services\Verification\VerifikasiLatar;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

/**
 * Satu langkah verifikasi berkas di latar: baca SATU file berikutnya yang belum selesai, lalu kirim ulang dirinya
 * untuk file sesudahnya; setelah file terakhir, periksa silang. Sebelum tiap file: cek jeda, token, dan anggaran.
 * Job per file (bukan satu job panjang) supaya restart worker paling banyak mengulang satu file.
 */
class VerifikasiBerkas implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $folderId, public string $token)
    {
        $this->onQueue(VerifikasiLatar::antreanSatuan());
    }

    public function handle(VerifikasiLatar $latar): void
    {
        $f = $this->berkas();
        if (! $f) {
            return; // rantai lama / duplikat: tidak memanggil AI
        }
        if ($f->verifikasi_status === 'menjeda') {
            $latar->tandaiDijeda($f, VerifikasiLatar::PESAN_JEDA);

            return;
        }
        if (! in_array($f->verifikasi_status, ['antre', 'berjalan'], true)) {
            return;
        }
        $a = $f->analyses()->first();
        if (! $a || ! $a->status?->isActive()) {
            $latar->akhiri($f, $a);

            return;
        }
        if ($this->queue === VerifikasiLatar::antreanMassal() && $latar->anggaranHabis()) {
            $latar->tandaiDijeda($f, VerifikasiLatar::PESAN_ANGGARAN, ReviewStatus::MenungguAnggaran);

            return;
        }

        $doc = $a->documents()->where('status', ReviewStatus::Antre->value)->orderBy('id')->first();
        // Klaim langkah ini; gagal bila status baru saja diubah (mis. tombol Jeda ditekan di antara cek dan update)
        $ok = FtpFolder::withoutGlobalScopes()->whereKey($f->id)->where('verifikasi_token', $this->token)
            ->whereIn('verifikasi_status', ['antre', 'berjalan'])
            ->update(['verifikasi_status' => 'berjalan', 'verifikasi_heartbeat_at' => now(),
                'verifikasi_file_aktif' => $doc?->name ?? 'Periksa silang (rekomendasi keseluruhan)']);
        if (! $ok) {
            if ($f = $this->berkas()) {
                $f->verifikasi_status === 'menjeda' && $latar->tandaiDijeda($f, VerifikasiLatar::PESAN_JEDA);
            }

            return;
        }

        if (! $doc) { // semua file selesai: tahap 2
            app()->call([new SynthesizeFolderAnalysis($a->id, true), 'handle']);
            $latar->akhiri($f->refresh(), $a->refresh());

            return;
        }

        app()->call([new ReadFolderDocument($doc->id, true), 'handle']);
        $doc->refresh();
        $f->refresh();
        $latar->hitung($f, $a);

        if ($doc->status === ReviewStatus::Gagal) {
            $pesan = "Gagal membaca {$doc->name}: " . mb_substr((string) $doc->error, 0, 300);
            $a->update(['status' => ReviewStatus::Gagal, 'error' => "Dihentikan: {$doc->name} gagal dibaca. Dokumen berikutnya tidak diproses.", 'finished_at' => now()]);
            $f->update(['analysis_status' => 'gagal']);
            $latar->tandaiGagal($f, $pesan);
            Audit::system('folder.analisa_gagal', $f, mb_substr($pesan, 0, 200), $a->requested_by);

            return;
        }
        if ($doc->status === ReviewStatus::Dibatalkan) { // ditahan anggaran (ReadFolderDocument menandai analisa menunggu anggaran)
            $latar->tandaiDijeda($f, VerifikasiLatar::PESAN_ANGGARAN, ReviewStatus::MenungguAnggaran);

            return;
        }

        self::dispatch($f->id, $this->token)->onQueue($this->queue ?? VerifikasiLatar::antreanSatuan());
    }

    /** Dipanggil saat job gagal di luar penanganan (mis. timeout): berkas jadi gagal, bisa dilanjutkan. */
    public function failed(?Throwable $e): void
    {
        // Diambil ulang karena retry_after terlewati padahal job pertama masih jalan (heartbeat segar): jangan timpa status
        if ($e instanceof MaxAttemptsExceededException && ($f = $this->berkas()) && $f->statusVerifikasi() === 'berjalan') {
            return;
        }
        if ($f = $this->berkas()) {
            app(VerifikasiLatar::class)->tandaiGagal($f, 'Verifikasi berhenti: ' . ($e?->getMessage() ?: 'job gagal'));
        }
    }

    private function berkas(): ?FtpFolder
    {
        $f = FtpFolder::withoutGlobalScopes()->find($this->folderId);

        return $f && $f->verifikasi_token === $this->token ? $f : null;
    }
}
