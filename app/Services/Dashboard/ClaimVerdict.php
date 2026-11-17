<?php

namespace App\Services\Dashboard;

use App\Models\FtpFolder;
use App\Support\VerificationRubric;

/** Vonis dan prioritas antrean klaim, tanpa AI. Bobot di config casemix.dashboard. */
class ClaimVerdict
{
    public const VERDICTS = ['siap' => 'Siap diajukan', 'tinjau' => 'Perlu ditinjau', 'blokir' => 'Terblokir', 'belum' => 'Belum dianalisa'];

    /** siap | tinjau | blokir | belum */
    public function verdict(FtpFolder $folder): string
    {
        return match (true) {
            $folder->analysis_status === 'gagal' => 'blokir',
            $folder->rubric_kurang === null => 'belum',
            $folder->state() === 'lengkap' => 'siap',
            $this->missingDocuments($folder) => 'blokir',
            default => 'tinjau',
        };
    }

    /** 0–100: bobot vonis + butir kurang + umur sejak tanggal FTP. Siap/belum hanya dari umur. */
    public function priority(FtpFolder $folder): int
    {
        $w = config('casemix.dashboard');
        $score = match ($this->verdict($folder)) {
            'blokir' => $w['weight_blokir'],
            'tinjau' => $w['weight_tinjau'],
            default => 0,
        };
        $score += min($w['per_kurang'] * (int) $folder->rubric_kurang, $w['max_kurang']);
        $score += min($this->ageDays($folder), $w['max_umur']);

        return (int) max(0, min(100, $score));
    }

    /** Hari sejak tanggal file di FTP (atau pertama terlihat). */
    public function ageDays(FtpFolder $folder): int
    {
        $from = $folder->files_modified_at ?? $folder->first_seen_at ?? $folder->created_at;

        return $from ? max(0, (int) $from->startOfDay()->diffInDays(now()->startOfDay())) : 0;
    }

    /** Tenggat pengajuan menurut SLA (hari) sejak tanggal FTP. */
    public function dueDate(FtpFolder $folder): ?\Carbon\CarbonInterface
    {
        $from = $folder->files_modified_at ?? $folder->first_seen_at ?? $folder->created_at;

        return $from?->copy()->addDays((int) config('casemix.dashboard.sla_days'));
    }

    private function missingDocuments(FtpFolder $folder): bool
    {
        $dok = array_filter(VerificationRubric::codes(), fn ($k) => str_starts_with($k, 'dok_'));

        return $folder->rubricItems()->whereIn('kode', $dok)->where('status', 'kurang')->exists();
    }
}
