<?php

namespace App\Services\Pnpk;

use App\Models\CodeSuggestion;
use App\Models\FtpFolder;
use App\Models\RegulasiButir;
use App\Models\RegulasiDokumen;
use App\Models\RegulasiIcd;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Pemakaian acuan regulasi (PNPK, Permenkes, BA, dst.) pada folder/klaim: hanya ICD & butir disetujui, dari dokumen yang
 * berlaku pada tanggal pulang (tanpa tanggal → status berlaku).
 *
 * Tingkat kecocokan butir: 0 = kode lengkap, 1 = prefix 3 digit, 2 = kaidah umum (tanpa cakupan kode).
 * Dalam tingkat yang sama: ba_kesepakatan > permenkes > kmk_pnpk > lainnya.
 */
class PnpkAcuan
{
    /** @var array<string, Collection> dokumen berlaku per tanggal (memo per permintaan) */
    private array $memo = [];

    /** Kode diagnosis utama/sekunder folder: saran kode ICD-10 yang tidak ditolak + diagnosa VClaim. @return string[] */
    public function diagnosisCodes(FtpFolder $folder): array
    {
        $k = $this->kodePerPosisi($folder);

        return array_values(array_unique(array_merge($k['utama'], $k['sekunder'])));
    }

    /** Kode per posisi (utama|sekunder: ICD-10; tindakan: ICD-9-CM). @return array{utama: string[], sekunder: string[], tindakan: string[]} */
    public function kodePerPosisi(FtpFolder $folder): array
    {
        $hasil = ['utama' => [], 'sekunder' => [], 'tindakan' => []];
        foreach ($this->saranKode($folder) as $c) {
            $hasil[$c->peran][] = $c->system === 'icd10' ? RegulasiIcd::normalize($c->code) : self::kode9($c->code);
        }
        foreach ([$folder->vclaimKlaim?->diagnosa, $folder->vclaimKunjungan?->diagnosa] as $teks) {
            $hasil['utama'] = array_merge($hasil['utama'], self::kodeDariTeks($teks));
        }

        return array_map(fn ($a) => array_values(array_unique($a)), $hasil);
    }

    /** Saran kode analisa terakhir (ICD-10 & ICD-9-CM) yang tidak ditolak. */
    public function saranKode(FtpFolder $folder): Collection
    {
        $id = CodeSuggestion::whereHas('analysis', fn ($q) => $q->where('ftp_folder_id', $folder->id))->max('folder_analysis_id');

        return $id ? CodeSuggestion::where('folder_analysis_id', $id)->whereIn('peran', ['utama', 'sekunder', 'tindakan'])
            ->where(fn ($q) => $q->whereNull('status_tindak_lanjut')->orWhereNotIn('status_tindak_lanjut', ['tidak_setuju', 'tidak_relevan']))
            ->orderBy('id')->get() : collect();
    }

    /** @return string[] kode ICD-10 yang tertulis di teks (mis. diagnosa VClaim) */
    public static function kodeDariTeks(?string $teks): array
    {
        if (! $teks || ! preg_match_all('/\b[A-Z][0-9]{2}(?:\.[0-9A-Z]{1,4})?\b/', strtoupper($teks), $m)) {
            return [];
        }

        return array_map(fn ($c) => RegulasiIcd::normalize($c), $m[0]);
    }

    public static function kode9(string $kode): string
    {
        return str_replace([' ', '.'], '', strtoupper($kode));
    }

    public function tanggalPulang(FtpFolder $folder): ?Carbon
    {
        $t = $folder->tgl_pulang ?? $folder->vclaimKlaim?->tgl_pulang ?? $folder->vclaimKunjungan?->tgl_plg_sep;

        return $t ? Carbon::parse($t) : null;
    }

    /** Dokumen berlaku pada tanggal, beserta ICD & butir disetujui. */
    public function dokumenBerlaku(?\DateTimeInterface $tanggal): Collection
    {
        return $this->memo[$tanggal?->format('Y-m-d') ?? '-'] ??= RegulasiDokumen::berlakuPada($tanggal)
            ->with(['icd' => fn ($q) => $q->where('status', 'disetujui'), 'butir' => fn ($q) => $q->where('status', 'disetujui')])
            ->orderBy('judul')->get()
            ->each(fn (RegulasiDokumen $d) => $d->butir->each(fn (RegulasiButir $b) => $b->setRelation('dokumen', $d)));
    }

    /** PNPK (KMK) yang cocok untuk folder, beserta butir disetujui per jenis. @return Collection<int, array{dokumen: RegulasiDokumen, butir: Collection}> */
    public function forFolder(FtpFolder $folder): Collection
    {
        $codes = $this->diagnosisCodes($folder);
        if (! $codes) {
            return collect();
        }

        return $this->dokumenBerlaku($this->tanggalPulang($folder))->where('jenis', 'kmk_pnpk')
            ->filter(fn (RegulasiDokumen $d) => $d->icd->contains(fn (RegulasiIcd $i) => collect($codes)->contains(fn ($c) => $i->cocok($c))))
            ->map(fn (RegulasiDokumen $d) => ['dokumen' => $d, 'butir' => $d->butir->groupBy('jenis')])
            ->filter(fn ($x) => $x['butir']->isNotEmpty())->values();
    }

    /** Tingkat kecocokan satu prefix terhadap daftar kode (titik diabaikan): 0 lengkap, 1 prefix 3 digit, null tidak cocok. */
    public static function tingkatPrefix(array $prefix, array $kode): ?int
    {
        $t = null;
        foreach ($prefix as $p) {
            $p = str_replace(['.', ' '], '', strtoupper((string) $p));
            if ($p === '') {
                continue;
            }
            foreach ($kode as $c) {
                $c = str_replace(['.', ' '], '', strtoupper((string) $c));
                if ($c === $p || (strlen($p) > 3 && str_starts_with($c, $p))) {
                    return 0;
                }
                if (str_starts_with($c, $p)) {
                    $t = 1;
                }
            }
        }

        return $t;
    }

    /**
     * Butir disetujui yang relevan untuk kode-kode kasus, diurutkan menurut prioritas.
     * Tiap butir diberi atribut ->tingkat (0|1|2).
     *
     * @param string[] $icd10
     * @param string[] $icd9
     * @param string[]|null $jenisDokumen batasi jenis dokumen
     * @return Collection<int, RegulasiButir>
     */
    public function kandidat(array $icd10, array $icd9 = [], ?string $inacbg = null, ?\DateTimeInterface $tanggal = null, ?array $jenisDokumen = null, bool $kaidahUmum = true): Collection
    {
        $hasil = collect();
        foreach ($this->dokumenBerlaku($tanggal) as $d) {
            if ($jenisDokumen && ! in_array($d->jenis, $jenisDokumen, true)) {
                continue;
            }
            $tingkatDok = self::tingkatPrefix($d->icd->pluck('kode')->all(), $icd10);
            foreach ($d->butir as $b) {
                $punyaCakupan = $b->cakupan_icd || $b->cakupan_icd9 || $b->cakupan_inacbg;
                $t = $punyaCakupan
                    ? collect([self::tingkatPrefix((array) $b->cakupan_icd, $icd10), self::tingkatPrefix((array) $b->cakupan_icd9, $icd9),
                        $inacbg ? self::tingkatPrefix((array) $b->cakupan_inacbg, [$inacbg]) : null])->filter(fn ($x) => $x !== null)->min()
                    : $tingkatDok;
                if ($t === null && $kaidahUmum && ! $punyaCakupan && $d->icd->isEmpty() && str_starts_with($b->jenis, 'kaidah_')) {
                    $t = 2;
                }
                if ($t === null) {
                    continue;
                }
                $b->setAttribute('tingkat', $t);
                $hasil->push($b);
            }
        }

        return $hasil->sortBy(fn (RegulasiButir $b) => sprintf('%d-%d-%010d', $b->tingkat,
            RegulasiDokumen::PRIORITAS[$b->dokumen->jenis] ?? 3, $b->id))->values();
    }

    /**
     * Butir disetujui yang disisipkan ke analisa, dibatasi casemix.regulasi.max_butir & max_token (±4 karakter/token).
     * Prioritas: kode lengkap > prefix 3 digit > kaidah umum; lalu BA > Permenkes > KMK PNPK > lainnya.
     *
     * @return Collection<int, RegulasiButir>
     */
    public function butirForFolder(FtpFolder $folder, ?int $max = null): Collection
    {
        $max ??= (int) config('casemix.regulasi.max_butir', 12);
        $maxToken = (int) config('casemix.regulasi.max_token', 3000);
        $k = $this->kodePerPosisi($folder);
        $icd10 = array_merge($k['utama'], $k['sekunder']);
        if (! $icd10 && ! $k['tindakan']) {
            return collect();
        }

        $hasil = collect();
        $token = 0;
        foreach ($this->kandidat($icd10, $k['tindakan'], $folder->vclaimKlaim?->inacbg_kode, $this->tanggalPulang($folder)) as $b) {
            $t = (int) ceil(mb_strlen(json_encode($this->baris($b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) / 4);
            if ($hasil->count() >= $max || $token + $t > $maxToken) {
                break;
            }
            $hasil->push($b);
            $token += $t;
        }

        return $hasil;
    }

    /** Yang dikirim ke AI per butir: ringkasan + kutipan + sitasi. Tidak pernah teks halaman/PDF. */
    public function baris(RegulasiButir $b): array
    {
        return array_filter(['id' => $b->id, 'acuan' => $b->dokumen?->judul, 'jenis_dokumen' => $b->dokumen?->jenisLabel(),
            'nomor' => $b->dokumen?->nomorTampil(), 'pasal' => $b->pasal, 'halaman' => $b->halaman, 'jenis' => $b->jenisLabel(),
            'ringkasan' => $b->ringkasan, 'kutipan' => $b->kutipan], fn ($v) => $v !== null && $v !== '');
    }

    /** Teks sisipan untuk prompt: hanya id, jenis, ringkasan, kutipan, sitasi. */
    public function promptBlock(Collection $butir): string
    {
        if ($butir->isEmpty()) {
            return '';
        }
        $rows = $butir->map(fn (RegulasiButir $b) => $this->baris($b))->values()->all();

        return "\n\nACUAN REGULASI (butir disetujui koordinator). Rujuk HANYA lewat \"id\" di bawah; jangan merujuk regulasi lain:\n"
            . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rujukan AI ke butir: id yang tidak dikirim dibuang sebelum disimpan.
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
