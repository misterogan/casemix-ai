<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class VclaimKlaim extends Model
{
    public const STATUS = [1 => 'Proses Verifikasi', 2 => 'Pending Verifikasi', 3 => 'Klaim'];

    /** Kategori status (urutan tampilan) => label. */
    public const KATEGORI = ['proses' => 'Proses verifikasi', 'pending' => 'Pending verifikasi', 'tidak_layak' => 'Tidak layak',
        'dispute' => 'Dispute', 'klaim' => 'Klaim', 'lainnya' => 'Lainnya'];

    protected $table = 'vclaim_klaim';

    protected static function booted(): void
    {
        static::saving(function (VclaimKlaim $k) {
            if (! $k->isDirty('status_kategori') && (! $k->status_kategori || $k->isDirty(['status_teks', 'status_kode']))) {
                $k->status_kategori = self::kategori($k->status_teks, (int) $k->status_kode);
            }
        });
    }

    /** Kategori dari teks status VClaim (case-insensitive); teks kosong memakai teks bawaan kode. */
    public static function kategori(?string $teks, int $kode): string
    {
        $t = strtolower(trim((string) ($teks ?? '')));
        if ($t === '') {
            $t = strtolower(self::STATUS[$kode] ?? '');
        }
        $kat = match (true) {
            str_contains($t, 'tidak layak') => 'tidak_layak',
            str_contains($t, 'dispute') => 'dispute',
            str_contains($t, 'pending') => 'pending',
            str_contains($t, 'proses') => 'proses',
            $t === 'klaim' || $kode === 3 => 'klaim',
            default => 'lainnya',
        };
        if ($kat === 'lainnya') {
            Log::warning('VClaim: teks status klaim tidak dikenal', ['status_teks' => $teks, 'status_kode' => $kode]);
        }

        return $kat;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_kode' => 'integer',
            'tgl_sep' => 'date', 'tgl_pulang' => 'date',
            'by_pengajuan' => 'decimal:2', 'by_setujui' => 'decimal:2', 'by_tarif_gruper' => 'decimal:2',
            'by_tarif_rs' => 'decimal:2', 'by_topup' => 'decimal:2',
            'first_seen_at' => 'datetime', 'status_changed_at' => 'datetime', 'synced_at' => 'datetime',
        ];
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(VclaimKlaimStatusHistory::class, 'no_sep', 'no_sep')->orderBy('terlihat_at');
    }
}
