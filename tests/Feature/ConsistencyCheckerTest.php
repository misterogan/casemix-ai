<?php

namespace Tests\Feature;

use App\Models\Claim;
use App\Services\Rules\ConsistencyChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji aturan pemeriksaan konsistensi E-Klaim (tanpa AI).
 * Jalankan:  php artisan test --filter=ConsistencyCheckerTest
 *
 * Setiap tes membuat satu berkas yang lengkap dan konsisten, lalu mengubah SATU hal
 * dan memastikan aturan yang tepat menangkapnya. Tambahkan tes baru setiap kali
 * menambah aturan di app/Services/Rules/ConsistencyChecker.php.
 */
class ConsistencyCheckerTest extends TestCase
{
    use RefreshDatabase;

    private const DOKUMEN_WAJIB = ['sep', 'resume_medis', 'rincian_biaya'];

    /** Berkas rawat inap dewasa yang lengkap dan konsisten (seharusnya tanpa temuan). */
    private function berkas(array $ubah = [], ?array $dokumen = null): Claim
    {
        $claim = Claim::create(array_merge([
            'nama_pasien' => 'Pasien Uji',
            'no_rm' => '000001',
            'no_sep' => '0301R0011126V000001',
            'tanggal_lahir' => '1980-05-10',
            'jenis_kelamin' => 'L',
            'jenis_rawat' => 'inap',
            'kelas_hak' => '2',
            'kelas_rawat' => '2',
            'naik_kelas' => false,
            'tanggal_masuk' => '2026-11-01',
            'tanggal_keluar' => '2026-11-04',
            'dpjp' => 'dr. Uji, Sp.PD',
            'diagnosis_utama' => 'Pneumonia',
            'tarif' => ['kamar' => 1500000, 'obat' => 500000],
        ], $ubah));

        foreach ($dokumen ?? self::DOKUMEN_WAJIB as $i => $jenis) {
            $claim->documents()->create([
                'type' => $jenis,
                'original_name' => "$jenis.pdf",
                'path' => "claims/uji/$jenis.pdf",
                'mime' => 'application/pdf',
                'size' => 1000,
                'sha256' => str_pad((string) $i, 64, '0'),
            ]);
        }

        return $claim->fresh();
    }

    private function periksa(Claim $claim): array
    {
        return app(ConsistencyChecker::class)->check($claim);
    }

    private function assertAdaTemuan(array $temuan, string $potonganJudul, string $tingkat): void
    {
        $cocok = collect($temuan)->first(fn ($f) => str_contains($f['judul'], $potonganJudul));
        $this->assertNotNull($cocok, "Temuan \"$potonganJudul\" tidak muncul. Yang muncul: "
            . collect($temuan)->pluck('judul')->implode(' | '));
        $this->assertSame($tingkat, $cocok['tingkat'], "Tingkat temuan \"$potonganJudul\" salah.");
    }

    private function assertTidakAdaTemuan(array $temuan, string $potonganJudul): void
    {
        $this->assertFalse(
            collect($temuan)->contains(fn ($f) => str_contains($f['judul'], $potonganJudul)),
            "Temuan \"$potonganJudul\" seharusnya tidak muncul."
        );
    }

    // ---------------------------------------------------------------- dasar

    public function test_berkas_lengkap_dan_konsisten_tidak_punya_temuan(): void
    {
        $this->assertSame([], $this->periksa($this->berkas()));
    }

    // ------------------------------------------------------------ identitas

    public function test_no_sep_kosong_kritis(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['no_sep' => null])), 'No. SEP kosong', 'kritis');
    }

    public function test_diagnosis_utama_kosong_kritis(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['diagnosis_utama' => ''])), 'Diagnosis utama kosong', 'kritis');
    }

    public function test_tanggal_lahir_setelah_tanggal_masuk_kritis(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['tanggal_lahir' => '2026-12-01'])), 'Tanggal lahir setelah tanggal masuk', 'kritis');
    }

    // ----------------------------------------------------------- kelengkapan

    public function test_dokumen_wajib_hilang_kritis(): void
    {
        $temuan = $this->periksa($this->berkas([], ['sep', 'rincian_biaya']));
        $this->assertAdaTemuan($temuan, 'Resume medis belum diunggah', 'kritis');
        $this->assertTidakAdaTemuan($temuan, 'SEP belum diunggah');
    }

    // ------------------------------------------------------- tanggal dan LOS

    public function test_rawat_inap_tanpa_tanggal_keluar_kritis(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['tanggal_keluar' => null])), 'Tanggal keluar kosong', 'kritis');
    }

    public function test_tanggal_keluar_sebelum_masuk_kritis(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['tanggal_keluar' => '2026-10-30'])), 'Tanggal keluar sebelum tanggal masuk', 'kritis');
    }

    public function test_rawat_jalan_lebih_dari_satu_hari_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas([
            'jenis_rawat' => 'jalan', 'kelas_hak' => null, 'kelas_rawat' => null,
            'tanggal_keluar' => '2026-11-02', 'tarif' => ['obat' => 100000],
        ]));
        $this->assertAdaTemuan($temuan, 'Rawat jalan lebih dari satu hari', 'perhatian');
    }

    // ------------------------------------------------------------------ bayi

    public function test_neonatus_tanpa_berat_lahir_kritis(): void
    {
        $temuan = $this->periksa($this->berkas(['tanggal_lahir' => '2026-10-25', 'berat_lahir' => null]));
        $this->assertAdaTemuan($temuan, 'Berat lahir kosong untuk pasien neonatus', 'kritis');
    }

    public function test_neonatus_dengan_berat_lahir_tidak_ditandai(): void
    {
        $temuan = $this->periksa($this->berkas(['tanggal_lahir' => '2026-10-25', 'berat_lahir' => 3100]));
        $this->assertTidakAdaTemuan($temuan, 'Berat lahir');
    }

    public function test_berat_lahir_pada_pasien_dewasa_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['berat_lahir' => 3000])), 'bukan neonatus', 'perhatian');
    }

    public function test_cara_masuk_lahir_di_rs_tidak_sesuai_umur_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['cara_masuk' => 'lahir_di_rs'])), 'Lahir di RS', 'perhatian');
    }

    public function test_berat_lahir_tidak_wajar_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tanggal_lahir' => '2026-10-31', 'berat_lahir' => 30]));
        $this->assertAdaTemuan($temuan, 'di luar rentang wajar', 'perhatian');
    }

    // ----------------------------------------------------------------- kelas

    public function test_naik_kelas_tetapi_kelas_sama_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['naik_kelas' => true])), 'Naik kelas dicentang', 'perhatian');
    }

    public function test_kelas_berbeda_tanpa_naik_kelas_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['kelas_rawat' => '1'])), 'tanpa keterangan naik kelas', 'perhatian');
    }

    public function test_naik_kelas_yang_benar_tidak_ditandai(): void
    {
        $temuan = $this->periksa($this->berkas(['kelas_rawat' => '1', 'naik_kelas' => true]));
        $this->assertTidakAdaTemuan($temuan, 'kelas');
    }

    // --------------------------------------------------------- rawat intensif

    public function test_rawat_intensif_melebihi_los_kritis(): void
    {
        $temuan = $this->periksa($this->berkas(['rawat_intensif_hari' => 10, 'tarif' => ['kamar' => 1, 'rawat_intensif' => 5000000]]));
        $this->assertAdaTemuan($temuan, 'melebihi LOS', 'kritis');
    }

    public function test_biaya_icu_tanpa_hari_icu_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'rawat_intensif' => 5000000]]));
        $this->assertAdaTemuan($temuan, 'biaya rawat intensif tanpa lama', 'perhatian');
    }

    public function test_hari_icu_tanpa_biaya_icu_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['rawat_intensif_hari' => 2])), 'tanpa biaya rawat intensif', 'perhatian');
    }

    public function test_ventilator_tanpa_rawat_intensif_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['ventilator_jam' => 12])), 'ventilator', 'perhatian');
    }

    // --------------------------------------------------------- biaya vs bukti

    public function test_biaya_lab_tanpa_hasil_lab_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'laboratorium' => 250000]]));
        $this->assertAdaTemuan($temuan, 'Biaya laboratorium tanpa hasil lab', 'perhatian');
    }

    public function test_biaya_lab_dengan_hasil_lab_tidak_ditandai(): void
    {
        $temuan = $this->periksa($this->berkas(
            ['tarif' => ['kamar' => 1, 'laboratorium' => 250000]],
            [...self::DOKUMEN_WAJIB, 'laboratorium']
        ));
        $this->assertTidakAdaTemuan($temuan, 'laboratorium');
    }

    public function test_biaya_radiologi_tanpa_hasil_radiologi_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'radiologi' => 350000]]));
        $this->assertAdaTemuan($temuan, 'Biaya radiologi tanpa hasil radiologi', 'perhatian');
    }

    public function test_biaya_bedah_tanpa_laporan_operasi_dan_tindakan_kritis(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'prosedur_bedah' => 8000000], 'tindakan' => null]));
        $this->assertAdaTemuan($temuan, 'tanpa laporan operasi', 'kritis');
        $this->assertAdaTemuan($temuan, 'tanpa tindakan tercatat', 'kritis');
    }

    public function test_biaya_bedah_lengkap_tidak_ditandai(): void
    {
        $temuan = $this->periksa($this->berkas(
            ['tarif' => ['kamar' => 1, 'prosedur_bedah' => 8000000], 'tindakan' => 'Apendektomi'],
            [...self::DOKUMEN_WAJIB, 'laporan_operasi']
        ));
        $this->assertTidakAdaTemuan($temuan, 'bedah');
    }

    public function test_biaya_darah_tanpa_catatan_transfusi_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'pelayanan_darah' => 400000]]));
        $this->assertAdaTemuan($temuan, 'tanpa catatan transfusi', 'perhatian');
    }

    public function test_biaya_kemoterapi_tanpa_daftar_obat_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas(['tarif' => ['kamar' => 1, 'obat_kemoterapi' => 3000000]]));
        $this->assertAdaTemuan($temuan, 'kemoterapi', 'perhatian');
    }

    public function test_biaya_kamar_pada_rawat_jalan_perhatian(): void
    {
        $temuan = $this->periksa($this->berkas([
            'jenis_rawat' => 'jalan', 'kelas_hak' => null, 'kelas_rawat' => null,
            'tanggal_keluar' => '2026-11-01', 'tarif' => ['kamar' => 200000],
        ]));
        $this->assertAdaTemuan($temuan, 'Biaya kamar pada rawat jalan', 'perhatian');
    }

    public function test_rawat_inap_tanpa_biaya_kamar_perhatian(): void
    {
        $this->assertAdaTemuan($this->periksa($this->berkas(['tarif' => ['obat' => 500000]])), 'Rawat inap tanpa biaya kamar', 'perhatian');
    }
}
