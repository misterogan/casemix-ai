<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Butir PNPK usulan AI. Kutipan wajib persis dari teks halaman; hanya butir disetujui yang dipakai. */
class PnpkButir extends Model
{
    protected $table = 'pnpk_butir';

    public const JENIS = [
        'kriteria_diagnosis' => 'Kriteria diagnosis',
        'pemeriksaan_penunjang' => 'Pemeriksaan penunjang',
        'indikasi_rawat_inap' => 'Indikasi rawat inap',
        'tata_laksana' => 'Tata laksana',
        'kriteria_pulang' => 'Kriteria pulang',
        'komplikasi' => 'Komplikasi',
        'lama_rawat' => 'Lama rawat',
        'lainnya' => 'Lainnya',
    ];

    protected $fillable = ['dokumen_id', 'jenis', 'ringkasan', 'kutipan', 'halaman', 'level_bukti', 'cocok_kutipan', 'status',
        'alasan_tolak', 'ditinjau_oleh', 'ditinjau_at'];

    protected function casts(): array
    {
        return ['cocok_kutipan' => 'boolean', 'halaman' => 'integer', 'ditinjau_at' => 'datetime'];
    }

    public function dokumen(): BelongsTo
    {
        return $this->belongsTo(PnpkDokumen::class, 'dokumen_id');
    }

    public function jenisLabel(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }
}
