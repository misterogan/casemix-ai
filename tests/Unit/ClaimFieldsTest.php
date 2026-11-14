<?php

namespace Tests\Unit;

use App\Support\ClaimFields;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Validasi nilai yang diusulkan AI sebelum ditampilkan ke petugas. Nilai yang ragu dibuang, bukan ditebak. */
class ClaimFieldsTest extends TestCase
{
    public static function kasus(): array
    {
        return [
            'tanggal Y-m-d' => ['tanggal_masuk', '2026-11-01', '2026-11-01'],
            'tanggal d/m/Y' => ['tanggal_masuk', '01/11/2026', '2026-11-01'],
            'tanggal dengan jam' => ['tanggal_masuk', '24-06-2023 08:08', '2023-06-24'],
            'tanggal tidak valid' => ['tanggal_masuk', '31/02/2026', null],
            'tanggal teks bebas' => ['tanggal_masuk', 'awal November', null],
            'SEP dengan spasi' => ['no_sep', '0301R001 1126V000133', '0301R0011126V000133'],
            'SEP terlalu pendek' => ['no_sep', '0301R00111', null],
            'RM angka' => ['no_rm', '000123', '000123'],
            'RM berisi huruf' => ['no_rm', 'RM-123', null],
            'enum kunci' => ['jenis_rawat', 'inap', 'inap'],
            'enum label' => ['jenis_rawat', 'Rawat Inap', 'inap'],
            'enum tidak dikenal' => ['cara_pulang', 'kabur', null],
            'kelas angka' => ['kelas_hak', '2', '2'],
            'rupiah bertitik' => ['tarif.kamar', 'Rp 1.500.000', 1500000],
            'rupiah dengan sen' => ['tarif.kamar', '1.500.000,00', 1500000],
            'rupiah angka' => ['tarif.obat', 250000, 250000],
            'rupiah nol dibuang' => ['tarif.obat', '0', null],
            'tekanan darah' => ['tekanan_darah', '120 / 80', '120/80'],
            'tekanan darah salah' => ['tekanan_darah', 'normal', null],
            'diagnosis tanpa kode' => ['diagnosis_utama', 'Pneumonia (J18.9)', 'Pneumonia'],
            'diagnosis label ICD' => ['diagnosis_utama', 'Diabetes melitus tipe 2 ICD-10: E11', 'Diabetes melitus tipe 2'],
            'string kosong' => ['dpjp', '  ', null],
            'kata null' => ['dpjp', 'null', null],
            'no peserta tidak diusulkan' => ['no_peserta', '0001234567890', null],
            'field tak dikenal' => ['status', 'disetujui', null],
        ];
    }

    #[DataProvider('kasus')]
    public function test_normalisasi(string $field, mixed $raw, mixed $expected): void
    {
        $this->assertSame($expected, ClaimFields::normalize($field, $raw));
    }

    public function test_tampilan(): void
    {
        $this->assertSame('01/11/2026', ClaimFields::display('tanggal_masuk', '2026-11-01'));
        $this->assertSame('Rawat inap', ClaimFields::display('jenis_rawat', 'inap'));
        $this->assertSame('Rp 1.500.000', ClaimFields::display('tarif.kamar', 1500000));
        $this->assertSame('—', ClaimFields::display('dpjp', null));
    }
}
