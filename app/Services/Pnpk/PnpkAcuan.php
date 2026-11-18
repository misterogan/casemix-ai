<?php

namespace App\Services\Pnpk;

use App\Models\CodeSuggestion;
use App\Models\FtpFolder;
use App\Models\PnpkButir;
use App\Models\PnpkDokumen;
use App\Models\PnpkIcd;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Pemakaian PNPK pada folder: hanya ICD & butir disetujui, dari PNPK yang berlaku pada tanggal pulang folder
 * (tanpa tanggal → status berlaku).
 */
class PnpkAcuan
{
    /** Kode diagnosis utama/sekunder folder: saran kode ICD-10 yang tidak ditolak + diagnosa VClaim. @return string[] */
    public function diagnosisCodes(FtpFolder $folder): array
    {
        $id = CodeSuggestion::whereHas('analysis', fn ($q) => $q->where('ftp_folder_id', $folder->id))->max('folder_analysis_id');
        $codes = $id ? CodeSuggestion::where('folder_analysis_id', $id)->where('system', 'icd10')->whereIn('peran', ['utama', 'sekunder'])
            ->where(fn ($q) => $q->whereNull('status_tindak_lanjut')->orWhereNotIn('status_tindak_lanjut', ['tidak_setuju', 'tidak_relevan']))
            ->pluck('code')->all() : [];
        foreach ([$folder->vclaimKlaim?->diagnosa, $folder->vclaimKunjungan?->diagnosa] as $teks) {
            if ($teks && preg_match_all('/\b[A-Z][0-9]{2}(?:\.[0-9A-Z]{1,2})?\b/', strtoupper($teks), $m)) {
                $codes = array_merge($codes, $m[0]);
            }
        }

        return array_values(array_unique(array_map(fn ($c) => PnpkIcd::normalize($c), $codes)));
    }

    public function tanggalPulang(FtpFolder $folder): ?Carbon
    {
        $t = $folder->tgl_pulang ?? $folder->vclaimKlaim?->tgl_pulang ?? $folder->vclaimKunjungan?->tgl_plg_sep;

        return $t ? Carbon::parse($t) : null;
    }

    /** PNPK yang cocok untuk folder, beserta butir disetujui per jenis. @return Collection<int, array{dokumen: PnpkDokumen, butir: Collection}> */
    public function forFolder(FtpFolder $folder): Collection
    {
        $codes = $this->diagnosisCodes($folder);
        if (! $codes) {
            return collect();
        }

        return PnpkDokumen::berlakuPada($this->tanggalPulang($folder))
            ->with(['icd' => fn ($q) => $q->where('status', 'disetujui'), 'butir' => fn ($q) => $q->where('status', 'disetujui')])
            ->orderBy('judul')->get()
            ->filter(fn (PnpkDokumen $d) => $d->icd->contains(fn (PnpkIcd $i) => collect($codes)->contains(fn ($c) => $i->cocok($c))))
            ->map(fn (PnpkDokumen $d) => ['dokumen' => $d, 'butir' => $d->butir->groupBy('jenis')])
            ->filter(fn ($x) => $x['butir']->isNotEmpty())->values();
    }

    /** Butir disetujui yang boleh disisipkan ke pipeline analisa. @return Collection<int, PnpkButir> */
    public function butirForFolder(FtpFolder $folder, int $max = 20): Collection
    {
        return $this->forFolder($folder)->flatMap(fn ($x) => $x['butir']->flatten())->take($max)->values();
    }

    /** Teks sisipan untuk prompt: hanya id, jenis, ringkasan, kutipan. */
    public function promptBlock(Collection $butir): string
    {
        if ($butir->isEmpty()) {
            return '';
        }
        $rows = $butir->map(fn (PnpkButir $b) => ['id' => $b->id, 'pnpk' => $b->dokumen?->judul, 'jenis' => $b->jenisLabel(),
            'ringkasan' => $b->ringkasan, 'kutipan' => $b->kutipan])->values()->all();

        return "\n\nACUAN PNPK (butir disetujui koordinator). Rujuk HANYA lewat \"id\" di bawah; jangan merujuk PNPK lain:\n"
            . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rujukan AI ke butir PNPK: id yang tidak dikirim dibuang sebelum disimpan.
     *
     * @param int[] $dikirim
     * @return array<int, array{id: int, catatan: string}>
     */
    public function filterRujukan(mixed $items, array $dikirim): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($r) => is_array($r) && is_numeric($r['id'] ?? null) && in_array((int) $r['id'], $dikirim, true))
            ->map(fn ($r) => ['id' => (int) $r['id'], 'catatan' => \App\Support\IcdGuard::strip(mb_substr((string) ($r['catatan'] ?? ''), 0, 300))])
            ->unique('id')->values()->all();
    }
}
