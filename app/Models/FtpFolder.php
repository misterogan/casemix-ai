<?php

namespace App\Models;

use App\Support\VerificationRubric;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Satu folder pasien di FTP Enigma, seperti terlihat pada sinkronisasi terakhir. */
class FtpFolder extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = [
        'belum' => 'Belum dianalisa',
        'proses' => 'Sedang dianalisa',
        'temuan' => 'Belum siap',
        'lengkap' => 'Siap diajukan',
        'berubah' => 'File berubah',
        'gagal' => 'Analisa gagal',
    ];

    /** Filter kecil "Sumber: Semua | FTP | Manual" di Antrean kerja dan Dashboard (?sumber=ftp|manual). */
    protected static function booted(): void
    {
        static::addGlobalScope('sumber_filter', function (Builder $q) {
            $sumber = self::sumberFilter();
            if ($sumber) {
                $q->where($q->getModel()->getTable() . '.sumber', $sumber);
            }
        });
    }

    public static function sumberFilter(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }
        $r = request();
        $s = $r?->query('sumber');

        return $r?->route() && $r->routeIs('work-queue.index', 'control.index') && in_array($s, ['ftp', 'manual'], true) ? $s : null;
    }

    protected function casts(): array
    {
        return [
            'files' => 'array',
            'files_modified_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'missing_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'rubric_kurang' => 'integer',
            'assigned_at' => 'datetime',
            'bpjs_status_at' => 'datetime',
            'bpjs_tindak_lanjut' => 'boolean',
            'eklaim_final_at' => 'datetime',
            'eklaim_terkirim_at' => 'datetime',
            'eklaim_dicek_at' => 'datetime',
            'eklaim_gagal_cek' => 'integer',
            'diajukan_manual_at' => 'datetime',
        ];
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(FolderAnalysis::class)->latest('id');
    }

    public function diajukanManualOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_manual_oleh');
    }

    /** Tahap pengajuan: terkirim | final (otomatis dari E-Klaim) | manual | dianalisa | null. */
    public function tahapPengajuan(): ?string
    {
        return $this->eklaim_status ?: ($this->diajukan_manual_at ? 'manual' : ($this->analysis_status === 'selesai' ? 'dianalisa' : null));
    }

    public function vclaimKlaim(): HasOne
    {
        return $this->hasOne(VclaimKlaim::class, 'no_sep', 'no_sep');
    }

    public function vclaimKunjungan(): HasOne
    {
        return $this->hasOne(VclaimKunjungan::class, 'no_sep', 'no_sep');
    }

    public function manualFiles(): HasMany
    {
        return $this->hasMany(ManualFile::class)->whereNull('dimusnahkan_at')->orderBy('id');
    }

    public function isManual(): bool
    {
        return $this->sumber === 'manual';
    }

    public function scopeSumber(Builder $q, ?string $sumber): Builder
    {
        return in_array($sumber, ['ftp', 'manual'], true) ? $q->where('ftp_folders.sumber', $sumber) : $q;
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FolderDocument::class);
    }

    /** Petugas yang ditugaskan Koordinator untuk menindaklanjuti folder ini. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function rubricItems(): HasMany
    {
        return $this->hasMany(RubricItem::class);
    }

    public function conditionSuggestions(): HasMany
    {
        return $this->hasMany(ConditionSuggestion::class)->orderBy('id');
    }

    /** Hitung ulang jumlah butir wajib yang kurang; ini satu-satunya penentu status Siap diajukan. */
    public function recomputeReadiness(): int
    {
        $n = $this->rubricItems()->where('status', 'kurang')->get(['kode'])
            ->filter(fn (RubricItem $i) => VerificationRubric::required($i->kode))->count();
        $this->update(['rubric_kurang' => $n]);

        return $n;
    }

    /** null bila rubrik belum dinilai. */
    public function ready(): ?bool
    {
        return $this->rubric_kurang === null ? null : $this->rubric_kurang === 0;
    }

    public function readinessLabel(): ?string
    {
        return match ($this->ready()) {
            null => null,
            true => 'Siap diajukan',
            false => "Belum siap ({$this->rubric_kurang} butir kurang)",
        };
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

    /** Status yang ditampilkan: belum | proses | temuan (belum siap) | lengkap (siap diajukan) | berubah | gagal */
    public function state(): string
    {
        return match (true) {
            $this->analysis_status === null => 'belum',
            in_array($this->analysis_status, ['antre', 'berjalan'], true) => 'proses',
            $this->analysis_status === 'gagal' => 'gagal',
            $this->analyzed_fingerprint !== $this->fingerprint => 'berubah',
            $this->rubric_kurang !== null => $this->rubric_kurang > 0 ? 'temuan' : 'lengkap',
            ($this->kritis_count + $this->perhatian_count) > 0 => 'temuan',
            default => 'lengkap',
        };
    }

    /** Status pindah (turunan missing_at): dipindahkan | hilang_tanpa_periksa | null (masih di FTP). */
    public function getMovedStatusAttribute(): ?string
    {
        if (! $this->missing_at) {
            return null;
        }

        return ($this->analyzed_at || $this->last_analysis_id) ? 'dipindahkan' : 'hilang_tanpa_periksa';
    }

    public function getMovedLabelAttribute(): ?string
    {
        return match ($this->moved_status) {
            'dipindahkan' => 'Sudah dipindahkan casemix',
            'hilang_tanpa_periksa' => 'Dipindah tanpa diperiksa CERMAT',
            default => null,
        };
    }

    /** Filter tab: aktif | dipindahkan | hilang_tanpa_periksa. */
    public function scopeMoved(Builder $q, string $tab): Builder
    {
        $pernah = fn ($w) => $w->whereNotNull('analyzed_at')->orWhereNotNull('last_analysis_id');

        return match ($tab) {
            'dipindahkan' => $q->whereNotNull('missing_at')->where($pernah),
            'hilang_tanpa_periksa' => $q->whereNotNull('missing_at')->whereNull('analyzed_at')->whereNull('last_analysis_id'),
            default => $q->whereNull('missing_at'),
        };
    }

    public function scopeState(Builder $q, ?string $state): Builder
    {
        return match ($state) {
            'belum' => $q->whereNull('analysis_status'),
            'proses' => $q->whereIn('analysis_status', ['antre', 'berjalan']),
            'gagal' => $q->where('analysis_status', 'gagal'),
            'berubah' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', '!=', 'fingerprint'),
            'temuan' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', 'fingerprint')->where('rubric_kurang', '>', 0),
            'lengkap' => $q->where('analysis_status', 'selesai')->whereColumn('analyzed_fingerprint', 'fingerprint')->where('rubric_kurang', 0),
            default => $q,
        };
    }

    /** File berdasarkan urutan di daftar (dipakai di URL, supaya path FTP tidak bisa diisi sembarang). */
    public function fileAt(int $index): ?array
    {
        return $this->files[$index] ?? null;
    }
}
