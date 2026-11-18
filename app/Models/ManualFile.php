<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Dokumen yang diunggah manual ke sebuah folder; dianalisa bersama file FTP folder itu. */
class ManualFile extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public const JENIS = [
        'resume_medis' => 'Resume medis',
        'sep' => 'SEP',
        'lab' => 'Laboratorium',
        'radiologi' => 'Radiologi',
        'resep' => 'Resep',
        'lainnya' => 'Lainnya',
    ];

    protected function casts(): array
    {
        return ['ukuran' => 'integer', 'dimusnahkan_at' => 'datetime'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FtpFolder::class, 'ftp_folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh');
    }

    public function jenisLabel(): string
    {
        return self::JENIS[$this->jenis_dokumen] ?? 'Belum dipilih';
    }

    /** Jenis untuk analisa (DocumentType). */
    public function docType(): ?string
    {
        return match ($this->jenis_dokumen) {
            'resume_medis' => 'resume_medis', 'sep' => 'sep', 'lab' => 'laboratorium', 'radiologi' => 'radiologi', 'resep' => 'daftar_obat',
            default => null,
        };
    }
}
