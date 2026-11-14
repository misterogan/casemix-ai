<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil baca AI atas satu file dalam analisa folder (tahap 1). */
class FolderDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'reused' => 'boolean',
            'summary' => 'array',
            'findings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(FolderAnalysis::class, 'folder_analysis_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FtpFolder::class, 'ftp_folder_id');
    }

    /** Sidik satu file: berubah bila ukuran atau waktu ubahnya berubah. */
    public static function fingerprintOf(array $file): string
    {
        return ($file['size'] ?? 0) . '|' . ($file['modified'] ?? 0);
    }

    public function typeLabel(): string
    {
        return DocumentType::tryFrom((string) $this->doc_type)?->label() ?? 'Lainnya';
    }

    public function isDone(): bool
    {
        return in_array($this->status, [ReviewStatus::Selesai, ReviewStatus::Gagal], true);
    }
}
