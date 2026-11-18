<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Butir acuan regulasi usulan AI. Kutipan wajib persis dari teks halaman; hanya butir disetujui yang dipakai. */
class RegulasiButir extends Model
{
    protected $table = 'regulasi_butir';

    public const JENIS = [
        'kriteria_diagnosis' => 'Kriteria diagnosis',
        'pemeriksaan_penunjang' => 'Pemeriksaan penunjang',
        'indikasi_rawat_inap' => 'Indikasi rawat inap',
        'tata_laksana' => 'Tata laksana',
        'kriteria_pulang' => 'Kriteria pulang',
        'komplikasi' => 'Komplikasi',
        'lama_rawat' => 'Lama rawat',
        'kaidah_diagnosis_utama' => 'Kaidah diagnosis utama',
        'kaidah_diagnosis_sekunder' => 'Kaidah diagnosis sekunder',
        'kaidah_kode_kombinasi' => 'Kaidah kode kombinasi',
        'kaidah_prosedur' => 'Kaidah prosedur',
        'kaidah_episode_readmisi' => 'Kaidah episode/readmisi',
        'syarat_berkas' => 'Syarat berkas',
        'syarat_penunjang' => 'Syarat penunjang',
        'kriteria_klinis_kesepakatan' => 'Kriteria klinis kesepakatan',
        'alasan_pending_umum' => 'Alasan pending umum',
        'mekanisme_dispute' => 'Mekanisme dispute',
        'batas_waktu' => 'Batas waktu',
        'larangan_fraud' => 'Larangan fraud',
        'lainnya' => 'Lainnya',
    ];

    /** Jenis butir yang diusulkan AI per jenis dokumen (selain ini: semua jenis). */
    public const JENIS_PER_DOKUMEN = [
        'kmk_pnpk' => ['kriteria_diagnosis', 'pemeriksaan_penunjang', 'indikasi_rawat_inap', 'tata_laksana', 'kriteria_pulang', 'komplikasi', 'lama_rawat', 'lainnya'],
        'permenkes' => ['kaidah_diagnosis_utama', 'kaidah_diagnosis_sekunder', 'kaidah_kode_kombinasi', 'kaidah_prosedur', 'kaidah_episode_readmisi',
            'syarat_berkas', 'batas_waktu', 'mekanisme_dispute', 'larangan_fraud', 'lainnya'],
        'ba_kesepakatan' => ['kriteria_klinis_kesepakatan', 'syarat_berkas', 'syarat_penunjang', 'kaidah_diagnosis_utama', 'kaidah_diagnosis_sekunder',
            'kaidah_kode_kombinasi', 'kaidah_prosedur', 'alasan_pending_umum', 'lainnya'],
    ];

    /** Kaidah umum yang dipakai tanpa cakupan kode: per posisi kode. */
    public const KAIDAH_POSISI = ['utama' => 'kaidah_diagnosis_utama', 'sekunder' => 'kaidah_diagnosis_sekunder', 'tindakan' => 'kaidah_prosedur'];

    public static function jenisUntuk(?string $jenisDokumen): array
    {
        return self::JENIS_PER_DOKUMEN[$jenisDokumen] ?? array_keys(self::JENIS);
    }

    protected $fillable = ['dokumen_id', 'jenis', 'ringkasan', 'kutipan', 'halaman', 'halaman_pdf', 'pasal', 'cakupan_icd', 'cakupan_icd9', 'cakupan_inacbg', 'level_bukti', 'cocok_kutipan', 'status',
        'alasan_tolak', 'ditinjau_oleh', 'ditinjau_at', 'diperiksa_manusia'];

    protected function casts(): array
    {
        return ['cocok_kutipan' => 'boolean', 'diperiksa_manusia' => 'boolean', 'halaman' => 'integer', 'halaman_pdf' => 'integer', 'ditinjau_at' => 'datetime',
            'cakupan_icd' => 'array', 'cakupan_icd9' => 'array', 'cakupan_inacbg' => 'array'];
    }

    public function dokumen(): BelongsTo
    {
        return $this->belongsTo(RegulasiDokumen::class, 'dokumen_id');
    }

    /** Nomor halaman yang disebut AI (label). */
    public function getHalamanLabelAttribute(): int
    {
        return (int) $this->halaman;
    }

    /** Halaman PDF tempat kutipan ditemukan; bila belum diverifikasi ulang, pakai label. */
    public function halamanBuka(): int
    {
        return (int) ($this->halaman_pdf ?: $this->halaman);
    }

    /** Hanya butir disetujui yang sudah diperiksa koder/dokter (wajib untuk draf sanggahan). */
    public function scopeDiperiksaManusia($q)
    {
        return $q->where('status', 'disetujui')->where('diperiksa_manusia', true);
    }

    public function jenisLabel(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }

    /** Sitasi "{nomor}, {pasal}, hal. N". */
    public function sitasi(): string
    {
        $d = $this->dokumen;

        return implode(', ', array_filter([$d?->nomorTampil() ?: $d?->judul, $this->pasal, 'hal. ' . $this->halamanBuka()]));
    }
}
