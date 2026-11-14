<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Hasil analisa AI atas satu folder FTP, disimpan agar tidak dianalisa ulang setiap kali dibuka. */
class FolderAnalysis extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'files_snapshot' => 'array',
            'findings' => 'array',
            'suggestions' => 'array',
            'extracted' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FtpFolder::class, 'ftp_folder_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function countBy(string $tingkat): int
    {
        return collect($this->findings ?? [])->where('tingkat', $tingkat)->count();
    }
}
