<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AiReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'findings' => 'array',
            'rule_findings' => 'array',
            'suggestions' => 'array',
            'documents_snapshot' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** Gabungan temuan aturan + AI, diurutkan dari yang paling berat. */
    public function allFindings(): Collection
    {
        $rules = collect($this->rule_findings ?? [])->map(fn ($f) => $f + ['sumber' => 'aturan']);
        $ai = collect($this->findings ?? [])->map(fn ($f) => $f + ['sumber' => 'ai']);

        return $rules->concat($ai)
            ->sortBy(fn ($f) => (Severity::tryFrom($f['tingkat'] ?? '') ?? Severity::Perhatian)->rank())
            ->values();
    }

    public function countBy(Severity $s): int
    {
        return $this->allFindings()->where('tingkat', $s->value)->count();
    }
}
