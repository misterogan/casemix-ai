<?php

namespace App\Models;

use App\Enums\ClaimStatus;
use App\Enums\DocumentType;
use App\Enums\ReviewStatus;
use App\Support\Eklaim;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Claim extends Model
{
    /** Field yang bila berubah membuat hasil pemeriksaan AI usang. */
    public const CONTENT_FIELDS = [
        'nama_pasien', 'no_rm', 'no_sep', 'no_peserta', 'tanggal_lahir', 'jenis_kelamin',
        'jenis_rawat', 'kelas_hak', 'kelas_rawat', 'naik_kelas', 'eksekutif',
        'tanggal_masuk', 'tanggal_keluar', 'cara_masuk', 'cara_pulang', 'berat_lahir',
        'rawat_intensif_hari', 'ventilator_jam', 'adl_sub_acute', 'adl_chronic',
        'pasien_tb', 'tekanan_darah', 'dpjp', 'diagnosis_utama', 'diagnosis_sekunder',
        'tindakan', 'tarif',
    ];

    protected $guarded = ['id', 'status', 'approved_by', 'approved_at', 'checklist_by', 'checklist_at'];

    protected function casts(): array
    {
        return [
            'status' => ClaimStatus::class,
            'tanggal_lahir' => 'date',
            'tanggal_masuk' => 'date',
            'tanggal_keluar' => 'date',
            'naik_kelas' => 'boolean',
            'eksekutif' => 'boolean',
            'pasien_tb' => 'boolean',
            'tarif' => 'array',
            'checklist' => 'array',
            'checklist_at' => 'datetime',
            'content_updated_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    // Relasi ------------------------------------------------------------

    public function documents(): HasMany
    {
        return $this->hasMany(ClaimDocument::class)->orderBy('created_at');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(AiReview::class)->latest('id');
    }

    public function latestReview(): HasOne
    {
        return $this->hasOne(AiReview::class)->latestOfMany();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'subject_id')
            ->where('subject_type', self::class)->latest('id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklistUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checklist_by');
    }

    // Scope -------------------------------------------------------------

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }

        return $q->where(fn ($w) => $w
            ->where('nama_pasien', 'like', "%$term%")
            ->orWhere('no_rm', 'like', "%$term%")
            ->orWhere('no_sep', 'like', "%$term%")
            ->orWhere('dpjp', 'like', "%$term%"));
    }

    // Aturan alur kerja -------------------------------------------------

    public function isLocked(): bool
    {
        return $this->status === ClaimStatus::Disetujui;
    }

    public function los(): ?int
    {
        if (! $this->tanggal_masuk || ! $this->tanggal_keluar) {
            return null;
        }
        $days = (int) $this->tanggal_masuk->diffInDays($this->tanggal_keluar, false);

        return $this->jenis_rawat === 'inap' ? max($days, 1) : max($days, 0);
    }

    public function ageInDaysAtAdmission(): ?int
    {
        if (! $this->tanggal_lahir || ! $this->tanggal_masuk) {
            return null;
        }

        return (int) $this->tanggal_lahir->diffInDays($this->tanggal_masuk, false);
    }

    /** @return array<DocumentType> */
    public function missingRequiredDocuments(): array
    {
        // `type` di-cast ke enum; bandingkan nilainya, bukan objeknya.
        $present = $this->documents->map(fn ($d) => $d->type instanceof DocumentType ? $d->type->value : $d->type)->unique()->all();

        return array_values(array_filter(
            DocumentType::requiredTypes(),
            fn (DocumentType $t) => ! in_array($t->value, $present, true)
        ));
    }

    public function documentsOfType(DocumentType $type)
    {
        return $this->documents->filter(fn ($d) => $d->type === $type)->values();
    }

    public function completedReview(): ?AiReview
    {
        return $this->reviews()->where('status', ReviewStatus::Selesai->value)->first();
    }

    public function reviewIsStale(?AiReview $review = null): bool
    {
        $review ??= $this->completedReview();
        if (! $review || ! $review->finished_at) {
            return true;
        }

        return $this->content_updated_at !== null
            && $this->content_updated_at->greaterThan($review->started_at ?? $review->finished_at);
    }

    public function checklistComplete(): bool
    {
        $c = $this->checklist ?? [];

        return collect(array_keys(Eklaim::CHECKLIST))->every(fn ($k) => ! empty($c[$k]));
    }

    /**
     * Syarat persetujuan. Mengembalikan daftar alasan yang belum terpenuhi (kosong = boleh disetujui).
     *
     * @return array<string>
     */
    public function approvalBlockers(): array
    {
        $reasons = [];
        if ($this->isLocked()) {
            $reasons[] = 'Berkas sudah disetujui.';
        }
        if (! $this->no_sep) {
            $reasons[] = 'No. SEP belum diisi.';
        }
        foreach ($this->missingRequiredDocuments() as $t) {
            $reasons[] = "Dokumen wajib belum ada: {$t->label()}.";
        }
        $review = $this->completedReview();
        if (! $review) {
            $reasons[] = 'Berkas belum diperiksa AI.';
        } elseif ($this->reviewIsStale($review)) {
            $reasons[] = 'Hasil pemeriksaan AI usang karena data atau dokumen berubah. Jalankan pemeriksaan ulang.';
        }
        if (! $this->checklistComplete()) {
            $reasons[] = 'Daftar cek petugas belum lengkap.';
        }

        return $reasons;
    }

    public function canBeApproved(): bool
    {
        return $this->approvalBlockers() === [];
    }

    /** Dipanggil setiap kali data/dokumen berubah: tandai hasil AI usang dan reset daftar cek. */
    public function markContentChanged(): void
    {
        $this->forceFill([
            'content_updated_at' => now(),
            'checklist' => null,
            'checklist_by' => null,
            'checklist_at' => null,
        ])->saveQuietly();
    }

    /** Hitung ulang status sesuai alur E-Klaim SIMRS. Status Disetujui tidak disentuh. */
    public function refreshStatus(): ClaimStatus
    {
        if ($this->isLocked()) {
            return $this->status;
        }
        $this->loadMissing('documents');

        $status = match (true) {
            ! $this->no_sep => ClaimStatus::SepBelumAda,
            $this->missingRequiredDocuments() !== [] => ClaimStatus::PerluDilengkapi,
            $this->completedReview() && ! $this->reviewIsStale() => ClaimStatus::SudahKoreksi,
            default => ClaimStatus::BelumKoreksi,
        };

        if ($this->status !== $status) {
            $this->forceFill(['status' => $status])->saveQuietly();
        }

        return $status;
    }

    public function tarifTotal(): float
    {
        return (float) collect($this->tarif ?? [])->sum(fn ($v) => (float) $v);
    }
}
