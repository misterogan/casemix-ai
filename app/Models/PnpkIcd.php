<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prefix ICD-10 yang dicakup sebuah PNPK. Harus ada di master icd_codes. */
class PnpkIcd extends Model
{
    protected $table = 'pnpk_icd';

    protected $fillable = ['dokumen_id', 'kode', 'sumber', 'status', 'alasan_tolak', 'ditinjau_oleh', 'ditinjau_at'];

    protected function casts(): array
    {
        return ['ditinjau_at' => 'datetime'];
    }

    public function dokumen(): BelongsTo
    {
        return $this->belongsTo(PnpkDokumen::class, 'dokumen_id');
    }

    /** Huruf besar, tanpa spasi; titik dipertahankan. */
    public static function normalize(string $kode): string
    {
        return strtoupper(preg_replace('/\s+/', '', $kode));
    }

    /** Prefix ada di master ICD-10 (kode persis atau kode turunan). */
    public static function adaDiMaster(string $kode): bool
    {
        $k = self::normalize($kode);
        if (! preg_match('/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/', $k)) {
            return false;
        }

        return IcdCode::where('system', 'icd10')->where(fn ($q) => $q->where('code', $k)->orWhere('code', 'like', $k . '%'))->exists();
    }

    /** Kode diagnosis cocok dengan prefix ini (titik diabaikan). */
    public function cocok(string $diagnosis): bool
    {
        return str_starts_with(str_replace('.', '', self::normalize($diagnosis)), str_replace('.', '', $this->kode));
    }
}
