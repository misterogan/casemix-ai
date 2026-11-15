<?php

namespace App\Models;

use App\Support\VerificationRubric;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu butir rubrik verifikasi (lihat VerificationRubric) untuk satu folder. */
class RubricItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_fingerprints' => 'array',
            'accepted_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'halaman' => 'integer',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FtpFolder::class, 'ftp_folder_id');
    }

    public function acceptor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function label(): string
    {
        return VerificationRubric::label($this->kode);
    }

    public function required(): bool
    {
        return VerificationRubric::required($this->kode);
    }

    /** Terkunci: tidak dikirim ulang ke AI selama dokumen sumbernya sama. */
    public function lockedFor(array $fingerprints): bool
    {
        return in_array($this->status, VerificationRubric::LOCKED, true) && $this->source_fingerprints == $fingerprints;
    }
}
