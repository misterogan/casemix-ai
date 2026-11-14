<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Support\ClaimFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Usulan isian data klaim hasil pembacaan dokumen oleh AI. */
class ClaimExtraction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'suggestions' => 'array',
            'documents_snapshot' => 'array',
            'applied_fields' => 'array',
            'applied_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /** Dokumen berkas sudah berubah sejak usulan dibuat (ditambah, dihapus, atau diganti). */
    public function isOutdated(Claim $claim): bool
    {
        $now = $claim->documents->map(fn ($d) => $d->id . ':' . $d->sha256)->sort()->values()->all();
        $then = collect($this->documents_snapshot ?? [])->map(fn ($d) => $d['id'] . ':' . $d['sha256'])->sort()->values()->all();

        return $now !== $then;
    }

    /**
     * Usulan yang berbeda dari data klaim saat ini, siap ditampilkan untuk dikonfirmasi.
     *
     * @return array<string, array{label: string, sekarang: mixed, nilai: mixed, dokumen: string, keyakinan: string, kosong: bool}>
     */
    public function pendingFor(Claim $claim): array
    {
        $out = [];
        foreach ($this->suggestions ?? [] as $field => $s) {
            $current = ClaimFields::current($claim, $field);
            if (ClaimFields::same($current, $s['nilai'])) {
                continue;
            }
            $out[$field] = [
                'label' => ClaimFields::label($field),
                'sekarang' => $current,
                'nilai' => $s['nilai'],
                'dokumen' => (string) ($s['dokumen'] ?? ''),
                'keyakinan' => (string) ($s['keyakinan'] ?? 'rendah'),
                'kosong' => $current === null || $current === '' || $current === 0 || $current === '-',
            ];
        }

        return $out;
    }
}
