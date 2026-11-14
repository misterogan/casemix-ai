<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu folder pasien di FTP Enigma, seperti terlihat pada sinkronisasi terakhir. */
class FtpFolder extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = [
        'belum' => 'Belum dianalisa',
        'proses' => 'Sedang dianalisa',
        'temuan' => 'Ada temuan',
        'lengkap' => 'Tidak ada temuan',
        'berubah' => 'File berubah',
        'gagal' => 'Analisa gagal',
    ];

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'files_modified_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(FolderAnalysis::class)->latest('id');
    }

    public function lastAnalysis(): BelongsTo
    {
        return $this->belongsTo(FolderAnalysis::class, 'last_analysis_id');
    }

    /** Sidik isi folder: berubah bila ada file ditambah, dihapus, atau diganti. */
    public static function fingerprintOf(array $files): string
    {
        $parts = collect($files)->map(fn ($f) => $f['name'] . '|' . $f['size'] . '|' . ($f['modified'] ?? 0))->sort()->values()->all();

        return hash('sha256', implode("\n", $parts));
    }

    /** Status yang ditampilkan: belum | proses | temuan | lengkap | berubah | gagal */
    public function state(): string
    {
        return match (true) {
            $this->analysis_status === null => 'belum',
            in_array($this->analysis_status, ['antre', 'berjalan'], true) => 'proses',
            $this->analysis_status === 'gagal' => 'gagal',
            $this->analyzed_fingerprint !== $this->fingerprint => 'berubah',
            ($this->kritis_count + $this->perhatian_count) > 0 => 'temuan',
            default => 'lengkap',
        };
    }

    public function scopeState(Builder $q, ?string $state): Builder
    {
        return match ($state) {
            'belum' => $q->whereNull('analysis_status'),
            'proses' => $q->whereIn('analysis_status', ['antre', 'berjalan']),
            'gagal' => $q->where('analysis_status', 'gagal'),
            'berubah' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', '!=', 'fingerprint'),
            'temuan' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', 'fingerprint')
                ->whereRaw('(kritis_count + perhatian_count) > 0'),
            'lengkap' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', 'fingerprint')
                ->where('kritis_count', 0)->where('perhatian_count', 0),
            default => $q,
        };
    }

    /** File berdasarkan urutan di daftar (dipakai di URL, supaya path FTP tidak bisa diisi sembarang). */
    public function fileAt(int $index): ?array
    {
        return $this->files[$index] ?? null;
    }
}
