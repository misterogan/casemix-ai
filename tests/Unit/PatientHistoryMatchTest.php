<?php

namespace Tests\Unit;

use App\Services\Import\SimrsFtp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pencocokan file riwayat pasien di FTP dengan No. RM.
 * Salah cocok berarti dokumen pasien lain ikut terlampir, jadi kasus "tidak boleh cocok" sama pentingnya.
 */
class PatientHistoryMatchTest extends TestCase
{
    public static function cocok(): array
    {
        return [
            'folder diawali RM' => ['000123_BUDI SANTOSO/lab.pdf', '000123'],
            'RM tanpa nol di depan' => ['123_BUDI/lab.pdf', '000123'],
            'nama file diawali RM' => ['2026/000123.pdf', '123'],
            'RM lalu spasi' => ['000123 - Hasil Lab.pdf', '000123'],
            'label RM di nama file' => ['lab/Hasil Lab RM 000123.pdf', '000123'],
            'label No. RM' => ['No.RM-123_ronsen.jpg', '000123'],
            'subfolder dalam' => ['arsip/2025/000123_BUDI/rajal/resume.pdf', '000123'],
        ];
    }

    public static function tidakCocok(): array
    {
        return [
            'RM lain yang lebih panjang' => ['001234_SITI/lab.pdf', '000123'],
            'RM lain berakhiran sama' => ['004123_ANI/lab.pdf', '000123'],
            'angka di tengah nama file' => ['lab_000123_x.pdf', '000123'],
            'tanggal memuat angka RM' => ['20251230_lab.pdf', '123'],
            'nomor SEP diawali angka RM' => ['0301R0011126V000133/resume.pdf', '000301'],
            'SEP berakhiran angka RM' => ['999_ANI_0301R0011126V000123/lab.pdf', '000123'],
            'label RM untuk RM lain' => ['Hasil Lab RM 0001234.pdf', '000123'],
            'kata lain berakhiran rm' => ['form 000123.pdf', '000999'],
            'RM kosong' => ['000123_BUDI/lab.pdf', '000'],
        ];
    }

    #[DataProvider('cocok')]
    public function test_file_milik_pasien_ditemukan(string $path, string $rm): void
    {
        $this->assertTrue(SimrsFtp::pathBelongsToRm($path, $rm), "$path seharusnya milik RM $rm");
    }

    #[DataProvider('tidakCocok')]
    public function test_file_pasien_lain_tidak_ikut(string $path, string $rm): void
    {
        $this->assertFalse(SimrsFtp::pathBelongsToRm($path, $rm), "$path tidak boleh dianggap milik RM $rm");
    }
}
