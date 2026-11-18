<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Tanda "Sudah dicek" pada satu temuan folder (Koordinator). */
class FolderTemuanCek extends Model
{
    protected $table = 'folder_temuan_cek';

    public $timestamps = false;

    protected $fillable = ['folder_id', 'temuan_key', 'user_id', 'dicek_at'];

    protected function casts(): array
    {
        return ['dicek_at' => 'datetime'];
    }

    /** Kunci stabil: hash jenis (tingkat) + file + halaman + judul. */
    public static function key(array $f): string
    {
        return sha1(implode('|', [$f['tingkat'] ?? '', $f['dokumen'] ?? '', (string) ($f['halaman'] ?? ''), trim((string) ($f['judul'] ?? ''))]));
    }

    /** Semua temuan folder (rekomendasi keseluruhan + per file), tanpa duplikat, dengan kunci & kelompok. */
    public static function temuan(?FolderAnalysis $done, iterable $documents = []): Collection
    {
        $kelompok = ['kritis' => 'wajib', 'perhatian' => 'perhatian'];

        return collect($done?->findings ?? [])
            ->merge(collect($documents)->flatMap(fn ($d) => $d->findings ?? []))
            ->filter(fn ($f) => is_array($f) && filled($f['judul'] ?? null))
            ->map(fn ($f) => $f + ['key' => self::key($f), 'kelompok' => $kelompok[$f['tingkat'] ?? ''] ?? 'info'])
            ->unique('key')->values();
    }
}
