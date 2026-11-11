<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    public const STATUS_LABELS = [
        'antre' => 'Dalam antrean',
        'memilah' => 'Memilah dokumen',
        'siap_ditinjau' => 'Siap ditinjau',
        'disimpan' => 'Disimpan',
        'gagal' => 'Gagal',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['committed_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isProcessing(): bool
    {
        return in_array($this->status, ['antre', 'memilah'], true);
    }
}
