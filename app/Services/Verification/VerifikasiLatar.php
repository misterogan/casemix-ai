<?php

namespace App\Services\Verification;

use App\Enums\ReviewStatus;
use App\Jobs\RunFolderAnalysis;
use App\Jobs\VerifikasiBerkas;
use App\Models\AiUsage;
use App\Models\FolderAnalysis;
use App\Models\FolderDocument;
use App\Models\FtpFolder;
use App\Services\Ai\AiBudget;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Verifikasi berkas di latar belakang (queue), bisa dijeda dan dilanjutkan.
 * Satu berkas hanya punya satu rantai job yang sah (verifikasi_token); klaim token atomik di DB,
 * jadi dua klik / dua tab / worker ganda tidak menghasilkan dua job yang memanggil AI untuk berkas yang sama.
 */
class VerifikasiLatar
{
    public const MAKS_MASSAL = 50;

    public const PESAN_ANGGARAN = 'Anggaran AI habis.';

    public const PESAN_JEDA = 'Dijeda oleh pengguna.';

    public function __construct(private AiBudget $budget) {}

    public static function antreanSatuan(): string
    {
        return (string) config('casemix.ai.queue', 'ai');
    }

    public static function antreanMassal(): string
    {
        return (string) config('casemix.ai.queue_massal', 'ai-bulk');
    }

    /**
     * Klaim berkas: status -> antre dengan token baru. Null bila sudah ada rantai hidup
     * (antre, atau berjalan/menjeda dengan heartbeat segar). $paksa melewati pengecekan (analisa baru).
     */
    public function klaim(FtpFolder $f, bool $paksa = false): ?string
    {
        $token = (string) Str::ulid();
        $basi = now()->subMinutes(FtpFolder::HEARTBEAT_BASI_MENIT);
        $n = FtpFolder::withoutGlobalScopes()->whereKey($f->id)
            ->when(! $paksa, fn ($q) => $q->where(fn ($q) => $q->whereNull('verifikasi_status')
                ->orWhereIn('verifikasi_status', ['dijeda', 'selesai', 'gagal'])
                ->orWhere(fn ($q) => $q->whereIn('verifikasi_status', ['berjalan', 'menjeda'])
                    ->where(fn ($q) => $q->whereNull('verifikasi_heartbeat_at')->orWhere('verifikasi_heartbeat_at', '<', $basi)))))
            ->update(['verifikasi_status' => 'antre', 'verifikasi_token' => $token, 'verifikasi_heartbeat_at' => now(),
                'verifikasi_pesan' => null, 'verifikasi_file_aktif' => null]);
        if (! $n) {
            return null;
        }
        $f->refresh();

        return $token;
    }

    /** Analisa baru: dokumen disiapkan (langsung tampil "menunggu"), lalu job berjalan di latar. */
    public function mulai(FtpFolder $f, int $userId, bool $all = false, bool $paksa = false, ?string $queue = null): FolderAnalysis
    {
        $a = FolderAnalysis::create(['ftp_folder_id' => $f->id, 'status' => ReviewStatus::Antre, 'requested_by' => $userId, 'paksa' => $paksa || $all]);
        $f->update(['analysis_status' => 'antre', 'last_analysis_id' => $a->id]);
        app(FolderAnalyzer::class)->prepare($a);
        if ($all) {
            $a->documents()->update(['status' => ReviewStatus::Antre->value, 'reused' => false, 'summary' => null, 'findings' => null, 'finished_at' => null]);
        }
        $this->klaim($f, true);
        $this->hitung($f, $a);
        RunFolderAnalysis::dispatch($a->id)->onQueue($queue ?? self::antreanSatuan());

        return $a;
    }

    /**
     * Lanjutkan dari file yang belum selesai (file selesai TIDAK dibaca ulang, supaya AI tidak dibayar dua kali).
     * Mengembalikan pesan penolakan, atau null bila dijalankan.
     *
     * @param  int[]  $ulangDokumen  dokumen tertentu yang diminta dibaca ulang (tombol proses satu file)
     */
    public function lanjut(FtpFolder $f, ?string $queue = null, array $ulangDokumen = []): ?string
    {
        $a = $f->analyses()->first();
        if (! $a) {
            return 'Belum ada analisa untuk dilanjutkan.';
        }
        $vs = $f->statusVerifikasi();
        $terhenti = $vs === 'terhenti';
        if (in_array($vs, FtpFolder::VERIFIKASI_AKTIF, true) || ($a->status?->isActive() && ! $terhenti && $vs !== 'dijeda')) {
            return 'Berkas ini sedang diverifikasi.';
        }
        $ulang = [ReviewStatus::Gagal->value, ReviewStatus::Dibatalkan->value];
        if ($terhenti) {
            $ulang[] = ReviewStatus::Berjalan->value; // worker mati di tengah file: file itu dibaca lagi
        }
        $pilih = fn ($q) => $q->whereIn('status', $ulang)->orWhereIn('id', $ulangDokumen);
        $sisa = $a->documents()->where(fn ($q) => $pilih($q)->orWhere('status', ReviewStatus::Antre->value))->count();
        if ($sisa === 0 && $a->status === ReviewStatus::Selesai) {
            return 'Tidak ada dokumen yang perlu dilanjutkan.';
        }
        if (! $this->klaim($f)) {
            return 'Berkas ini sedang diverifikasi.';
        }
        $a->documents()->where($pilih)->update(['status' => ReviewStatus::Antre->value, 'error' => null, 'reused' => false]);
        $a->update(['status' => ReviewStatus::Berjalan, 'error' => null]);
        $f->update(['analysis_status' => 'berjalan']);
        $this->jalankan($f, $a, $queue);

        return null;
    }

    /** Kirim job langkah berikutnya untuk token berkas saat ini. */
    public function jalankan(FtpFolder $f, FolderAnalysis $a, ?string $queue = null): void
    {
        $this->hitung($f, $a);
        VerifikasiBerkas::dispatch($f->id, (string) $f->verifikasi_token)->onQueue($queue ?? self::antreanSatuan());
    }

    /** Jeda: antre -> dijeda langsung; berjalan -> menjeda (file yang sedang dibaca AI diselesaikan dulu). */
    public function jeda(FtpFolder $f): bool
    {
        $q = fn () => FtpFolder::withoutGlobalScopes()->whereKey($f->id);
        if ($q()->where('verifikasi_status', 'antre')->update(['verifikasi_status' => 'dijeda', 'verifikasi_pesan' => self::PESAN_JEDA, 'verifikasi_file_aktif' => null])) {
            $this->tandaiAnalisa($f->refresh(), ReviewStatus::Dijeda);

            return true;
        }

        return (bool) $q()->where('verifikasi_status', 'berjalan')->update(['verifikasi_status' => 'menjeda']);
    }

    /** Dipanggil job: berkas berhenti dengan rapi (progres tersimpan di status per file). */
    public function tandaiDijeda(FtpFolder $f, string $pesan, ReviewStatus $analisa = ReviewStatus::Dijeda): void
    {
        $f->update(['verifikasi_status' => 'dijeda', 'verifikasi_pesan' => $pesan, 'verifikasi_file_aktif' => null, 'verifikasi_heartbeat_at' => now()]);
        $this->tandaiAnalisa($f, $analisa);
    }

    public function tandaiGagal(FtpFolder $f, string $pesan): void
    {
        $f->update(['verifikasi_status' => 'gagal', 'verifikasi_pesan' => mb_substr($pesan, 0, 500), 'verifikasi_file_aktif' => null, 'verifikasi_heartbeat_at' => now()]);
    }

    /** Samakan status verifikasi dengan status akhir analisa (setelah periksa silang). */
    public function akhiri(FtpFolder $f, ?FolderAnalysis $a): void
    {
        match ($a?->status) {
            ReviewStatus::Selesai => $f->update(['verifikasi_status' => 'selesai', 'verifikasi_pesan' => null, 'verifikasi_file_aktif' => null, 'verifikasi_heartbeat_at' => now()]),
            ReviewStatus::MenungguAnggaran => $this->tandaiDijeda($f, self::PESAN_ANGGARAN, ReviewStatus::MenungguAnggaran),
            ReviewStatus::Dijeda => $this->tandaiDijeda($f, self::PESAN_JEDA),
            default => $this->tandaiGagal($f, (string) ($a?->error ?: 'Analisa tidak selesai.')),
        };
    }

    public function hitung(FtpFolder $f, ?FolderAnalysis $a): void
    {
        if (! $a) {
            return;
        }
        $docs = fn () => FolderDocument::where('folder_analysis_id', $a->id);
        $attrs = ['verifikasi_file_total' => $docs()->count(), 'verifikasi_file_selesai' => $docs()->where('status', ReviewStatus::Selesai->value)->count(),
            'verifikasi_heartbeat_at' => now()];
        FtpFolder::withoutGlobalScopes()->whereKey($f->id)->update($attrs);
        $f->forceFill($attrs)->syncOriginalAttributes(array_keys($attrs));
    }

    /** Anggaran habis untuk verifikasi massal (tanpa cadangan analisa). */
    public function anggaranHabis(): bool
    {
        $b = $this->budget->budget();

        return $b !== null && $this->budget->spent() >= $b;
    }

    /** Sisa anggaran AI bulan berjalan (USD), atau null bila tanpa batas. */
    public function sisaAnggaran(): ?float
    {
        $b = $this->budget->budget();

        return $b === null ? null : max(0.0, round($b - $this->budget->spent(), 6));
    }

    /** Rata-rata biaya per berkas = rata-rata SUM(ai_usage.biaya_usd) per analisa, dari 50 analisa terakhir. */
    public function rataBiayaPerBerkas(): float
    {
        $per = AiUsage::where('jenis', 'analisa')->whereNotNull('ref_id')->groupBy('ref_id')
            ->orderByDesc('ref_id')->limit(50)->selectRaw('ref_id, SUM(biaya_usd) as total')->pluck('total');

        return $per->isEmpty() ? (float) config('casemix.budget.default_job_cost_usd', 0.2) : round((float) $per->avg(), 6);
    }

    /**
     * Rencana verifikasi massal.
     *
     * @return array{mulai: Collection, lanjut: Collection, lewati: array<int, string>, perkiraan_usd: float, sisa_usd: ?float, rata_usd: float, pesan: ?string}
     */
    public function rencana(Collection $folders, bool $ulang): array
    {
        $mulai = collect();
        $lanjut = collect();
        $lewati = [];
        foreach ($folders as $f) {
            $vs = $f->statusVerifikasi();
            $aktifLama = in_array($f->analysis_status, ['antre', 'berjalan'], true) && $vs === null;
            if ($f->missing_at && ! FolderDocument::where('ftp_folder_id', $f->id)->whereNotNull('stored_path')->exists()) {
                $lewati[$f->id] = 'Tidak ada di FTP';
            } elseif (in_array($vs, FtpFolder::VERIFIKASI_AKTIF, true) || $aktifLama) {
                $lewati[$f->id] = 'Sedang berjalan';
            } elseif (in_array($vs, ['dijeda', 'gagal', 'terhenti'], true) || in_array($f->analysis_status, ['gagal', 'menunggu_anggaran', 'dijeda'], true)) {
                $lanjut->push($f);
            } elseif ($f->analysis_status === 'selesai' && ! $ulang) {
                $lewati[$f->id] = 'Sudah selesai';
            } else {
                $mulai->push($f);
            }
        }
        $rata = $this->rataBiayaPerBerkas();
        $perkiraan = round(($mulai->count() + $lanjut->count()) * $rata, 4);
        $sisa = $this->sisaAnggaran();
        $pesan = $sisa !== null && $perkiraan > $sisa
            ? sprintf('Perkiraan biaya US$%s melebihi sisa anggaran AI bulan ini (US$%s). Kurangi jumlah berkas atau minta Admin menaikkan anggaran.',
                number_format($perkiraan, 2), number_format($sisa, 2))
            : null;

        return ['mulai' => $mulai, 'lanjut' => $lanjut, 'lewati' => $lewati, 'perkiraan_usd' => $perkiraan, 'sisa_usd' => $sisa, 'rata_usd' => $rata, 'pesan' => $pesan];
    }

    private function tandaiAnalisa(FtpFolder $f, ReviewStatus $status): void
    {
        $a = $f->analyses()->first();
        if ($a && ($a->status?->isActive() || $a->status === ReviewStatus::Dijeda)) {
            $a->update(['status' => $status]);
            $f->update(['analysis_status' => $status->value]);
        }
    }
}
