<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Dokumen acuan regulasi (PNPK, Permenkes, BA kesepakatan, dst.). PDF resmi = sumber kebenaran;
 * hanya butir/ICD disetujui yang dipakai.
 */
class RegulasiDokumen extends Model
{
    protected $table = 'regulasi_dokumen';

    public const STATUS = ['berlaku' => 'Berlaku', 'diganti' => 'Diganti', 'dicabut' => 'Dicabut'];

    public const STATUS_TONE = ['berlaku' => 'success', 'diganti' => 'gray', 'dicabut' => 'error'];

    public const PROSES = ['antre' => 'Antre', 'ekstrak' => 'Mengekstrak teks', 'usulan' => 'AI menyusun usulan',
        'siap_tinjau' => 'Siap ditinjau', 'selesai' => 'Selesai ditinjau', 'gagal' => 'Gagal'];

    public const JENIS = ['permenkes' => 'Permenkes', 'kmk_pnpk' => 'KMK PNPK', 'ba_kesepakatan' => 'BA kesepakatan', 'perpres' => 'Perpres',
        'peraturan_bpjs' => 'Peraturan BPJS', 'surat_edaran' => 'Surat edaran', 'juknis' => 'Juknis', 'lainnya' => 'Lainnya'];

    public const JENIS_TONE = ['permenkes' => 'info', 'kmk_pnpk' => 'success', 'ba_kesepakatan' => 'warning', 'perpres' => 'info',
        'peraturan_bpjs' => 'gray', 'surat_edaran' => 'gray', 'juknis' => 'gray', 'lainnya' => 'gray'];

    /** Prioritas dalam tingkat kecocokan yang sama (kecil = didahulukan). */
    public const PRIORITAS = ['ba_kesepakatan' => 0, 'permenkes' => 1, 'kmk_pnpk' => 2];

    public const CAKUPAN = ['koding' => 'Koding', 'verifikasi_klaim' => 'Verifikasi klaim', 'medis' => 'Medis', 'tarif' => 'Tarif',
        'fraud' => 'Fraud', 'umum' => 'Umum'];

    /** Cakupan bawaan per jenis dokumen (unggah/impor tanpa pilihan cakupan). */
    public const CAKUPAN_BAWAAN = ['kmk_pnpk' => ['medis'], 'permenkes' => ['koding'], 'ba_kesepakatan' => ['koding', 'verifikasi_klaim'],
        'peraturan_bpjs' => ['verifikasi_klaim'], 'juknis' => ['verifikasi_klaim']];

    /** Tab halaman Acuan regulasi => filter. */
    public const TAB = ['semua' => 'Semua', 'koding' => 'Koding', 'verifikasi_klaim' => 'Verifikasi klaim', 'medis' => 'Medis (PNPK)', 'lainnya' => 'Lainnya'];

    protected $fillable = ['judul', 'jenis', 'nomor', 'cakupan', 'mencabut_teks', 'mencabut_id', 'nomor_kmk', 'tahun', 'tanggal_berlaku', 'status',
        'diganti_oleh_id', 'sumber_url', 'path', 'sha256', 'jumlah_halaman', 'status_proses', 'error', 'diunggah_oleh', 'perlu_dilengkapi'];

    protected $attributes = ['jenis' => 'kmk_pnpk'];

    protected static function booted(): void
    {
        // Data lama/alias PNPK: nomor ↔ nomor_kmk tetap sinkron untuk KMK PNPK; cakupan bawaan medis.
        static::saving(function (RegulasiDokumen $d) {
            if ($d->jenis === 'kmk_pnpk') {
                if ($d->isDirty('nomor_kmk') && ! $d->isDirty('nomor')) {
                    $d->nomor = $d->nomor_kmk;
                } elseif ($d->isDirty('nomor')) {
                    $d->nomor_kmk = $d->nomor;
                }
                $d->cakupan = $d->cakupan ?: ['medis'];
            }
        });
    }

    protected function casts(): array
    {
        return ['tanggal_berlaku' => 'date', 'tahun' => 'integer', 'jumlah_halaman' => 'integer', 'finished_at' => 'datetime',
            'perlu_dilengkapi' => 'boolean', 'cakupan' => 'array'];
    }

    public function halaman(): HasMany
    {
        return $this->hasMany(RegulasiHalaman::class, 'dokumen_id')->orderBy('halaman');
    }

    public function icd(): HasMany
    {
        return $this->hasMany(RegulasiIcd::class, 'dokumen_id')->orderBy('kode');
    }

    public function butir(): HasMany
    {
        return $this->hasMany(RegulasiButir::class, 'dokumen_id')->orderBy('halaman')->orderBy('id');
    }

    public function penggantiDokumen(): BelongsTo
    {
        return $this->belongsTo(RegulasiDokumen::class, 'diganti_oleh_id');
    }

    public function digantikan(): HasMany
    {
        return $this->hasMany(RegulasiDokumen::class, 'diganti_oleh_id');
    }

    /** Dokumen yang dicabut oleh dokumen ini. */
    public function mencabutDokumen(): BelongsTo
    {
        return $this->belongsTo(RegulasiDokumen::class, 'mencabut_id');
    }

    /** Dokumen yang mencabut dokumen ini. */
    public function pencabut(): HasMany
    {
        return $this->hasMany(RegulasiDokumen::class, 'mencabut_id');
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }

    public function jenisLabel(): string
    {
        return self::JENIS[$this->jenis] ?? (string) $this->jenis;
    }

    /** Nama tab di penampil dokumen halaman folder/klaim (a[data-preview] mencocokkan nama ini). */
    public function namaPenampil(): string
    {
        return 'Acuan · ' . ($this->nomorTampil() ?: $this->judul) . ' #' . $this->id;
    }

    public function nomorTampil(): ?string
    {
        return $this->nomor ?: $this->nomor_kmk;
    }

    /** Filter tab: koding | verifikasi_klaim | medis (KMK PNPK atau cakupan medis) | lainnya. */
    public function scopeTab(Builder $q, ?string $tab): Builder
    {
        $punya = fn (Builder $w, string $c) => $w->where('cakupan', 'like', '%"' . $c . '"%');

        return match ($tab) {
            'koding', 'verifikasi_klaim' => $punya($q, $tab),
            'medis' => $q->where(fn ($w) => $w->where('jenis', 'kmk_pnpk')->orWhere(fn ($x) => $punya($x, 'medis'))),
            'lainnya' => $q->where('jenis', '!=', 'kmk_pnpk')->where(fn ($w) => $w->whereNull('cakupan')
                ->orWhere(fn ($x) => $x->where('cakupan', 'not like', '%"koding"%')->where('cakupan', 'not like', '%"verifikasi_klaim"%')
                    ->where('cakupan', 'not like', '%"medis"%'))),
            default => $q,
        };
    }

    /**
     * Dokumen yang berlaku pada tanggal tertentu: sudah berlaku, belum dicabut pada tanggal itu, dan belum digantikan versi
     * yang berlaku pada/sebelum tanggal itu. Dokumen dicabut tetap dipakai untuk tanggal sebelum pencabutnya berlaku.
     * Tanpa tanggal → status berlaku.
     */
    public function scopeBerlakuPada(Builder $q, ?\DateTimeInterface $tanggal): Builder
    {
        if (! $tanggal) {
            return $q->where('status', 'berlaku');
        }
        $tgl = $tanggal->format('Y-m-d');
        $pencabutBerlaku = fn ($p) => $p->whereDate('tanggal_berlaku', '<=', $tgl);

        return $q->whereDate('tanggal_berlaku', '<=', $tgl)
            ->where(fn ($w) => $w->where('status', '!=', 'dicabut')
                ->orWhereHas('pencabut', fn ($p) => $p->whereDate('tanggal_berlaku', '>', $tgl)))
            ->whereDoesntHave('pencabut', $pencabutBerlaku)
            ->where(fn ($w) => $w->whereNull('diganti_oleh_id')
                ->orWhereHas('penggantiDokumen', fn ($p) => $p->whereDate('tanggal_berlaku', '>', $tgl)));
    }

    public function pdfPath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('local')->path($this->path);
    }
}
