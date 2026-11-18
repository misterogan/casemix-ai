<?php

namespace App\Services\Regulasi;

use App\Enums\ReviewStatus;
use App\Models\FtpFolder;
use App\Models\RegulasiButir;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Services\Pnpk\PnpkAcuan;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Panel "Dasar sanggahan": butir disetujui dari BA kesepakatan, Permenkes, dan PNPK yang cocok diagnosis/prosedur/INA-CBG,
 * dikelompokkan, plus daftar periksa syarat berkas & penunjang.
 */
class DasarSanggahan
{
    public const STATUS_KLAIM = ['pending', 'tidak_layak', 'dispute'];

    public const KELOMPOK = ['ba_kesepakatan' => 'Kesepakatan Kemenkes–BPJS', 'permenkes' => 'Kaidah koding', 'kmk_pnpk' => 'Dasar medis (PNPK)'];

    private const SYARAT = ['syarat_berkas', 'syarat_penunjang'];

    /** Kata kunci isi butir syarat → jenis dokumen hasil analisa yang memenuhinya. */
    private const DETEKSI = [
        'laboratorium' => ['laborator', 'lab ', 'darah', 'hematolog', 'kultur', 'urinalisis', 'kimia klinik'],
        'radiologi' => ['radiolog', 'rontgen', 'foto toraks', 'foto thorax', 'x-ray', 'ct scan', 'ct-scan', 'usg', 'mri'],
        'laporan_operasi' => ['laporan operasi', 'laporan tindakan'],
        'resume_medis' => ['resume medis', 'resume'],
        'daftar_obat' => ['resep', 'daftar obat'],
        'rincian_biaya' => ['rincian biaya', 'billing'],
        'sep' => ['sep '],
    ];

    public function __construct(private PnpkAcuan $acuan) {}

    public static function bolehKlaim(?VclaimKlaim $k): bool
    {
        return $k && in_array($k->status_kategori, self::STATUS_KLAIM, true);
    }

    public function untukFolder(FtpFolder $folder): array
    {
        $k = $this->acuan->kodePerPosisi($folder);

        return $this->susun(array_merge($k['utama'], $k['sekunder']), $k['tindakan'], $folder->vclaimKlaim?->inacbg_kode,
            $this->acuan->tanggalPulang($folder), $this->jenisDokumen($folder));
    }

    public function untukKlaim(?VclaimKlaim $klaim, ?VclaimKunjungan $kunjungan, ?FtpFolder $folder): array
    {
        $k = $folder ? $this->acuan->kodePerPosisi($folder) : ['utama' => [], 'sekunder' => [], 'tindakan' => []];
        $icd10 = array_values(array_unique(array_merge($k['utama'], $k['sekunder'], PnpkAcuan::kodeDariTeks($kunjungan?->diagnosa))));
        $t = $klaim?->tgl_pulang ?? $kunjungan?->tgl_plg_sep ?? $folder?->tgl_pulang;

        return $this->susun($icd10, $k['tindakan'], $klaim?->inacbg_kode, $t ? Carbon::parse($t) : null,
            $folder ? $this->jenisDokumen($folder) : collect());
    }

    /** @return array{kelompok: array<string, Collection>, periksa: Collection, semua: Collection, terkonfirmasi: Collection, kode: array} */
    public function susun(array $icd10, array $icd9, ?string $inacbg, ?\DateTimeInterface $tgl, Collection $jenisDok): array
    {
        $semua = (! $icd10 && ! $icd9 && ! $inacbg) ? collect() : $this->acuan->kandidat($icd10, $icd9, $inacbg, $tgl, array_keys(self::KELOMPOK))
            ->reject(fn (RegulasiButir $b) => $b->tingkat === 2 && $b->dokumen->jenis !== 'permenkes')->values();
        $syarat = $semua->filter(fn (RegulasiButir $b) => in_array($b->jenis, self::SYARAT, true));
        $kelompok = [];
        foreach (self::KELOMPOK as $jenis => $label) {
            $isi = $semua->filter(fn (RegulasiButir $b) => $b->dokumen->jenis === $jenis && ! in_array($b->jenis, self::SYARAT, true))->values();
            if ($isi->isNotEmpty()) {
                $kelompok[$label] = $isi;
            }
        }

        return [
            'kelompok' => $kelompok,
            'periksa' => $syarat->map(fn (RegulasiButir $b) => ['butir' => $b, 'otomatis' => $this->terdeteksi($b, $jenisDok)])->values(),
            'semua' => $semua,
            'terkonfirmasi' => $semua->where('diperiksa_manusia', true)->values(),
            'kode' => ['icd10' => $icd10, 'icd9' => $icd9, 'inacbg' => $inacbg],
        ];
    }

    /** Jenis dokumen yang terbaca di analisa terakhir folder. */
    private function jenisDokumen(FtpFolder $folder): Collection
    {
        $a = $folder->analyses()->first();

        return $a ? $a->documents()->where('status', ReviewStatus::Selesai->value)->pluck('doc_type')->unique()->values() : collect();
    }

    private function terdeteksi(RegulasiButir $b, Collection $jenisDok): bool
    {
        $isi = mb_strtolower($b->ringkasan . ' ' . $b->kutipan) . ' ';
        foreach (self::DETEKSI as $jenis => $kata) {
            if ($jenisDok->contains($jenis) && collect($kata)->contains(fn ($k) => str_contains($isi, $k))) {
                return true;
            }
        }

        return false;
    }
}
