<?php

namespace App\Services\Regulasi;

use App\Models\FtpFolder;
use App\Models\IcdCode;
use App\Models\RegulasiButir;
use App\Models\RegulasiIcd;
use App\Services\Pnpk\PnpkAcuan;
use Illuminate\Support\Collection;

/**
 * Panel "Dasar koding": per kode ICD-10 (DU/DS) dan ICD-9-CM, butir acuan disetujui yang cakupannya cocok prefix
 * plus kaidah umum sesuai posisinya. Hanya menampilkan acuan; keputusan koding tetap pada koder/DPJP.
 */
class DasarKoding
{
    public const PERAN = ['utama' => 'Diagnosis utama', 'sekunder' => 'Diagnosis sekunder', 'tindakan' => 'Tindakan (ICD-9-CM)'];

    private ?bool $adaMaster = null;

    public function __construct(private PnpkAcuan $acuan) {}

    /** @return Collection<int, array{kode: string, judul: ?string, peran: string, system: string, alasan: ?string, tidak_di_who: bool, butir: Collection}> */
    public function untukFolder(FtpFolder $folder): Collection
    {
        $tgl = $this->acuan->tanggalPulang($folder);
        $items = $this->acuan->saranKode($folder)->map(fn ($c) => ['kode' => $c->system === 'icd10' ? RegulasiIcd::normalize($c->code) : $c->code,
            'judul' => $c->title, 'peran' => $c->peran, 'system' => $c->system, 'alasan' => $c->alasan]);
        foreach ([$folder->vclaimKlaim?->diagnosa, $folder->vclaimKunjungan?->diagnosa] as $teks) {
            foreach (PnpkAcuan::kodeDariTeks($teks) as $k) {
                if (! $items->contains('kode', $k)) {
                    $items->push(['kode' => $k, 'judul' => null, 'peran' => 'utama', 'system' => 'icd10', 'alasan' => 'Diagnosa tercatat di VClaim']);
                }
            }
        }

        return $items->unique(fn ($x) => $x['peran'] . $x['kode'])->values()->map(fn ($x) => $x + [
            'tidak_di_who' => $x['system'] === 'icd10' && ! $this->adaDiWho($x['kode']),
            'butir' => $this->butirUntuk($x['kode'], $x['peran'], $x['system'], $tgl),
        ]);
    }

    /** Butir untuk satu kode: cakupan cocok (PNPK hanya bila butirnya sendiri bercakupan kode) + kaidah umum posisi ini. */
    public function butirUntuk(string $kode, string $peran, string $system, ?\DateTimeInterface $tgl): Collection
    {
        $icd9 = $system !== 'icd10';
        $kaidah = RegulasiButir::KAIDAH_POSISI[$peran] ?? null;

        return $this->acuan->kandidat($icd9 ? [] : [$kode], $icd9 ? [PnpkAcuan::kode9($kode)] : [], null, $tgl)
            ->filter(fn (RegulasiButir $b) => $b->tingkat === 2
                ? $b->jenis === $kaidah
                : ($b->dokumen->jenis !== 'kmk_pnpk' || $b->cakupan_icd || $b->cakupan_icd9))
            ->values();
    }

    /** Kode persis ada di master ICD-10 WHO 2010 (master kosong → tidak diperiksa). */
    public function adaDiWho(string $kode): bool
    {
        $this->adaMaster ??= IcdCode::where('system', 'icd10')->exists();
        if (! $this->adaMaster) {
            return true;
        }
        $k = RegulasiIcd::normalize($kode);

        return IcdCode::where('system', 'icd10')->whereIn('code', [$k, str_replace('.', '', $k)])->exists();
    }

    /** Teks siap tempel: kode, alasan singkat, daftar sitasi. */
    public function teksSalin(Collection $items): string
    {
        return $items->map(function ($x) {
            $baris = [sprintf('%s — %s%s', $x['kode'], self::PERAN[$x['peran']] ?? $x['peran'], $x['judul'] ? " ({$x['judul']})" : '')];
            if ($x['tidak_di_who']) {
                $baris[] = 'Peringatan: kode tidak ada di ICD-10 WHO 2010 (kemungkinan ICD-10-CM).';
            }
            $baris[] = 'Alasan: ' . ($x['butir']->first()?->ringkasan ?? $x['alasan'] ?? '—');
            foreach ($x['butir'] as $b) {
                $baris[] = '- ' . $b->ringkasan . ' [' . $b->sitasi() . ']' . ($b->diperiksa_manusia ? '' : ' (belum diperiksa koder/dokter)');
            }

            return implode("\n", $baris);
        })->implode("\n\n");
    }
}
