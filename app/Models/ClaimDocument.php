<?php

namespace App\Models;

use App\Enums\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ClaimDocument extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['extracted_text'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'has_text_layer' => 'boolean',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function absolutePath(): string
    {
        return Storage::disk(config('casemix.storage_disk'))->path($this->path);
    }

    public function isPdf(): bool
    {
        return $this->mime === 'application/pdf';
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function humanSize(): string
    {
        $b = $this->size;

        return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
    }
}
