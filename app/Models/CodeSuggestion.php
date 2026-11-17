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

    /** Cek format longgar; master kode hanya memberi nama & lencana, tidak membuang saran. */
    public const FORMAT = [
        'icd10' => '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,2})?$/',
        'icd9cm' => '/^[0-9]{1,3}(\.[0-9]{1,3})?$/',
    ];

    protected $fillable = ['folder_analysis_id', 'system', 'peran', 'code', 'title', 'alasan', 'kaidah_koding', 'sumber', 'bukti',
        'folder_document_id', 'halaman', 'keyakinan', 'tercantum_di_resume', 'status_tindak_lanjut'];

    protected function casts(): array
    {
        return ['tercantum_di_resume' => 'boolean', 'halaman' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if ($m = IcdCode::lookup($c->system, $c->code)) {
                $c->title = mb_substr($m->title, 0, 255);
            }
        });
    }

    private ?IcdCode $masterCache = null;

    private bool $masterLoaded = false;

    public function master(): ?IcdCode
    {
        if (! $this->masterLoaded) {
            $this->masterCache = IcdCode::lookup($this->system, $this->code);
            $this->masterLoaded = true;
        }

        return $this->masterCache;
    }

    /** Lencana kaidah dari master: [warna, teks]. Tidak membuang saran. */
    public function lencana(): array
    {
        $m = $this->master();
        if (! $m) {
            return [['abu', 'tidak ditemukan di master']];
        }
        $l = [];
        if (! $m->valid) {
            $l[] = ['oranye', 'kode kategori, perlu digit lengkap'];
        }
        if ($this->peran === 'utama' && ! $m->accpdx) {
            $l[] = ['merah', 'tidak boleh jadi diagnosis utama'];
        }
        if ($m->asterisk) {
            $l[] = ['biru', 'kode asterisk, perlu pasangan dagger'];
        }

        return $l;
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
