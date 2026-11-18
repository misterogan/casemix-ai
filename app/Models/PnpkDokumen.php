<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Dokumen PNPK (KMK). PDF resmi adalah sumber kebenaran; hanya butir/ICD disetujui yang dipakai. */
class PnpkDokumen extends Model
{
    protected $table = 'pnpk_dokumen';

    public const STATUS = ['berlaku' => 'Berlaku', 'diganti' => 'Diganti', 'dicabut' => 'Dicabut'];

    public const STATUS_TONE = ['berlaku' => 'success', 'diganti' => 'gray', 'dicabut' => 'error'];

    public const PROSES = ['antre' => 'Antre', 'ekstrak' => 'Mengekstrak teks', 'usulan' => 'AI menyusun usulan',
        'siap_tinjau' => 'Siap ditinjau', 'selesai' => 'Selesai ditinjau', 'gagal' => 'Gagal'];

    protected $fillable = ['judul', 'nomor_kmk', 'tahun', 'tanggal_berlaku', 'status', 'diganti_oleh_id', 'sumber_url', 'path',
        'sha256', 'jumlah_halaman', 'status_proses', 'error', 'diunggah_oleh'];

    protected function casts(): array
    {
        return ['tanggal_berlaku' => 'date', 'tahun' => 'integer', 'jumlah_halaman' => 'integer', 'finished_at' => 'datetime'];
    }

    public function halaman(): HasMany
    {
        return $this->hasMany(PnpkHalaman::class, 'dokumen_id')->orderBy('halaman');
    }

    public function icd(): HasMany
    {
        return $this->hasMany(PnpkIcd::class, 'dokumen_id')->orderBy('kode');
    }

    public function butir(): HasMany
    {
        return $this->hasMany(PnpkButir::class, 'dokumen_id')->orderBy('halaman')->orderBy('id');
    }

    public function penggantiDokumen(): BelongsTo
    {
        return $this->belongsTo(self::class, 'diganti_oleh_id');
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }

    /**
     * PNPK yang berlaku pada tanggal tertentu: sudah berlaku, tidak dicabut, dan belum digantikan versi yang
     * berlaku pada/sebelum tanggal itu. Tanpa tanggal → status berlaku.
     */
    public function scopeBerlakuPada(Builder $q, ?\DateTimeInterface $tanggal): Builder
    {
        if (! $tanggal) {
            return $q->where('status', 'berlaku');
        }
        $tgl = $tanggal->format('Y-m-d');

        return $q->where('status', '!=', 'dicabut')->whereDate('tanggal_berlaku', '<=', $tgl)
            ->where(fn ($w) => $w->whereNull('diganti_oleh_id')
                ->orWhereHas('penggantiDokumen', fn ($p) => $p->whereDate('tanggal_berlaku', '>', $tgl)));
    }

    public function pdfPath(): string
    {
        return storage_path('app/private/' . $this->path);
    }
}
