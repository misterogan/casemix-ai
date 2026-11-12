<?php

namespace Tests\Unit;

use App\Services\Import\DocumentGuesser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Menguji pembacaan nama folder (NoRM_NAMA_NoSEP) dan tebakan jenis dokumen dari nama file FTP SIMRS.
 * Jalankan:  php artisan test --filter=DocumentGuesserTest
 * Bila SIMRS memakai nama file baru, tambahkan contohnya di namaFile() lalu sesuaikan DocumentGuesser.
 */
class DocumentGuesserTest extends TestCase
{
    public static function namaFile(): array
    {
        return [
            ['SEP Rawat Jalan.pdf', 'sep'],
            ['SEP Rawat Inap.pdf', 'sep'],
            ['Resume Medis Rawat Jalan .pdf', 'resume_medis'],
            ['Resume Medis Rawat Inap.pdf', 'resume_medis'],
            ['Billing.pdf', 'rincian_biaya'],
            ['Rincian Biaya.pdf', 'rincian_biaya'],
            ['Hasil Lab.pdf', 'laboratorium'],
            ['Laboratorium.pdf', 'laboratorium'],
            ['Expertise Thorax.pdf', 'radiologi'],
            ['Radiologi.pdf', 'radiologi'],
            ['Laporan Operasi.pdf', 'laporan_operasi'],
            ['Resep.pdf', 'daftar_obat'],
            ['CPPT Rajal.pdf', 'tambahan'],
            ['Surat Kontrol.pdf', 'tambahan'],
        ];
    }

    #[DataProvider('namaFile')]
    public function test_jenis_dokumen_dari_nama_file(string $file, string $jenis): void
    {
        $this->assertSame($jenis, DocumentGuesser::type($file)->value, "Nama file: $file");
    }

    public function test_jenis_rawat_dari_nama_file(): void
    {
        $this->assertSame('jalan', DocumentGuesser::careType(['SEP Rawat Jalan.pdf', 'CPPT Rajal.pdf', 'Billing.pdf']));
        $this->assertSame('inap', DocumentGuesser::careType(['SEP Rawat Inap.pdf', 'Billing.pdf']));
        $this->assertNull(DocumentGuesser::careType(['Billing.pdf']));
    }

    public function test_folder_lengkap(): void
    {
        $this->assertSame(
            ['no_rm' => '003660', 'nama' => 'NAMA PASIEN', 'no_sep' => '0030R0011126V000133'],
            DocumentGuesser::parseFolder('003660_NAMA PASIEN_0030R0011126V000133')
        );
    }

    public function test_folder_dengan_titik_di_nama(): void
    {
        $this->assertSame('Tn. NAMA PASIEN', DocumentGuesser::parseFolder('010120_Tn. NAMA PASIEN_0030R0011126V000118')['nama']);
        $this->assertSame('NAMA B.NAMA', DocumentGuesser::parseFolder('009992_NAMA B.NAMA_0030R0011126V000124')['nama']);
        $this->assertSame('0030R0011126V000139', DocumentGuesser::parseFolder('009919_NAMA . G_0030R0011126V000139')['no_sep']);
    }

    public function test_folder_tanpa_sep(): void
    {
        $this->assertSame(['no_rm' => '012345', 'nama' => 'NAMA PASIEN', 'no_sep' => null], DocumentGuesser::parseFolder('012345_NAMA PASIEN'));
    }

    public function test_folder_tidak_sesuai_pola(): void
    {
        $this->assertNull(DocumentGuesser::parseFolder('folder lain'));
    }
}
