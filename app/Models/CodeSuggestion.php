<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saran kode ICD-10 2010 / ICD-9-CM 2010 dari AI. Keputusan tetap koder/DPJP; tidak ditulis ke Enigma. */
class CodeSuggestion extends Model
{
    public const SYSTEMS = ['icd10', 'icd9cm'];

    public const PERAN = ['utama', 'sekunder', 'tindakan'];

    public const SUMBER = ['resume', 'cppt', 'penunjang', 'lab', 'lainnya'];

    public const KEYAKINAN = ['kuat', 'sedang'];

    public const STATUS = ['ditanyakan', 'setuju', 'tidak_setuju', 'tidak_relevan'];

    /** Cek format saja (tanpa master kode). */
    public const FORMAT = [
        'icd10' => '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/',
        'icd9cm' => '/^[0-9]{2}(\.[0-9]{1,2})?$/',
    ];

    protected $fillable = ['folder_analysis_id', 'system', 'peran', 'code', 'title', 'alasan', 'kaidah_koding', 'sumber', 'bukti',
        'folder_document_id', 'halaman', 'keyakinan', 'tercantum_di_resume', 'status_tindak_lanjut'];

    protected function casts(): array
    {
        return ['tercantum_di_resume' => 'boolean', 'halaman' => 'integer'];
    }

    public static function validFormat(string $system, string $code): bool
    {
        return isset(self::FORMAT[$system]) && preg_match(self::FORMAT[$system], $code) === 1;
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(FolderAnalysis::class, 'folder_analysis_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FolderDocument::class, 'folder_document_id');
    }
}
