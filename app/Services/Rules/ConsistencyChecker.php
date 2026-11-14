<?php

namespace App\Services\Rules;

use App\Enums\DocumentType;
use App\Enums\Severity;
use App\Models\Claim;
use App\Support\Eklaim;

/**
 * Pemeriksaan konsistensi field E-Klaim berbasis aturan (tanpa AI).
 * Hasilnya pasti dan murah, lalu ikut dikirim ke AI sebagai konteks.
 * Tambahkan aturan baru dari riwayat pending/dispute BPJS rumah sakit di sini.
 */
class ConsistencyChecker
{
    /** @var array<int, array{tingkat: string, kategori: string, judul: string, uraian: string}> */
    private array $out = [];

    public function check(Claim $claim): array
    {
        $this->out = [];
        $claim->loadMissing('documents');

        $this->identitas($claim);
        $this->dokumenWajib($claim);
        $this->tanggalDanLos($claim);
        $this->bayiDanBeratLahir($claim);
        $this->kelas($claim);
        $this->rawatIntensif($claim);
        $this->biayaVsBukti($claim);

        return $this->out;
    }

    private function add(Severity $s, string $kategori, string $judul, string $uraian): void
    {
        $this->out[] = ['tingkat' => $s->value, 'kategori' => $kategori, 'judul' => $judul, 'uraian' => $uraian];
    }

    private function identitas(Claim $c): void
    {
        if (! $c->no_sep) {
            $this->add(Severity::Kritis, 'Identitas', 'No. SEP kosong', 'Klaim tidak bisa diproses di E-Klaim tanpa SEP dari V-Claim.');
        }
        foreach ($c->missingRequiredFields() as $field => $label) {
            if ($field === 'tanggal_masuk') {
                continue; // ditangani di tanggalDanLos
            }
            $this->add(Severity::Kritis, 'Identitas', "$label belum diisi",
                'Isi sesuai SEP dan resume medis. Berkas hasil impor FTP tidak menebak field ini.');
        }
        if (! trim((string) $c->diagnosis_utama)) {
            $this->add(Severity::Kritis, 'Diagnosis', 'Diagnosis utama kosong', 'Isi diagnosis utama sesuai resume medis.');
        }
    }

    private function dokumenWajib(Claim $c): void
    {
        foreach ($c->missingRequiredDocuments() as $t) {
            $this->add(Severity::Kritis, 'Kelengkapan', "{$t->label()} belum diunggah", 'Dokumen wajib untuk verifikasi Vedika.');
        }
    }

    private function tanggalDanLos(Claim $c): void
    {
        if (! $c->tanggal_masuk) {
            $this->add(Severity::Kritis, 'Tanggal', 'Tanggal masuk belum diisi', 'Isi tanggal masuk sesuai SEP. LOS tidak bisa dihitung.');

            return;
        }
        if ($c->jenis_rawat === 'inap' && ! $c->tanggal_keluar) {
            $this->add(Severity::Kritis, 'Tanggal', 'Tanggal keluar kosong', 'Rawat inap wajib memiliki tanggal keluar untuk menghitung LOS.');

            return;
        }
        if ($c->tanggal_keluar && $c->tanggal_keluar->lt($c->tanggal_masuk)) {
            $this->add(Severity::Kritis, 'Tanggal', 'Tanggal keluar sebelum tanggal masuk', 'Periksa kembali tanggal pada SEP dan resume medis.');

            return;
        }
        if ($c->jenis_rawat === 'jalan' && $c->tanggal_keluar && ! $c->tanggal_keluar->isSameDay($c->tanggal_masuk)) {
            $this->add(Severity::Perhatian, 'Tanggal', 'Rawat jalan lebih dari satu hari',
                'Tanggal masuk dan keluar rawat jalan berbeda. Pastikan jenis rawat benar.');
        }
    }

    private function bayiDanBeratLahir(Claim $c): void
    {
        $umur = $c->ageInDaysAtAdmission();
        if ($umur === null) {
            if ($c->cara_masuk === 'lahir_di_rs' || $c->berat_lahir) {
                $this->add(Severity::Perhatian, 'Bayi', 'Tanggal lahir kosong', 'Tanggal lahir diperlukan untuk memeriksa berat lahir dan cara masuk.');
            }

            return;
        }
        if ($umur < 0) {
            $this->add(Severity::Kritis, 'Identitas', 'Tanggal lahir setelah tanggal masuk', 'Periksa tanggal lahir pasien.');

            return;
        }
        if ($umur <= 28 && ! $c->berat_lahir) {
            $this->add(Severity::Kritis, 'Bayi', 'Berat lahir kosong untuk pasien neonatus',
                "Pasien berumur $umur hari saat masuk. Field berat lahir wajib diisi di E-Klaim.");
        }
        if ($umur > 28 && $c->berat_lahir) {
            $this->add(Severity::Perhatian, 'Bayi', 'Berat lahir diisi untuk pasien bukan neonatus',
                "Pasien berumur $umur hari saat masuk. Pastikan berat lahir memang diperlukan.");
        }
        if ($c->cara_masuk === 'lahir_di_rs' && $umur > 1) {
            $this->add(Severity::Perhatian, 'Bayi', 'Cara masuk "Lahir di RS" tidak sesuai umur',
                "Pasien berumur $umur hari saat masuk.");
        }
        if ($c->berat_lahir && ($c->berat_lahir < 300 || $c->berat_lahir > 6500)) {
            $this->add(Severity::Perhatian, 'Bayi', 'Berat lahir di luar rentang wajar',
                "Tercatat {$c->berat_lahir} gram. Pastikan satuannya gram.");
        }
    }

    private function kelas(Claim $c): void
    {
        if ($c->jenis_rawat !== 'inap' || ! $c->kelas_hak || ! $c->kelas_rawat) {
            return;
        }
        $sama = $c->kelas_hak === $c->kelas_rawat;
        if ($c->naik_kelas && $sama) {
            $this->add(Severity::Perhatian, 'Kelas', 'Naik kelas dicentang, tetapi kelas rawat sama dengan hak kelas',
                'Hapus tanda naik kelas atau perbaiki kelas rawat.');
        }
        if (! $c->naik_kelas && ! $sama) {
            $this->add(
                Severity::Perhatian,
                'Kelas',
                'Kelas rawat berbeda dengan hak kelas tanpa keterangan naik kelas',
                'Pastikan ada persetujuan naik kelas, atau catat alasan titip kelas.'
            );
        }
    }

    private function rawatIntensif(Claim $c): void
    {
        $hari = (int) $c->rawat_intensif_hari;
        $biaya = (float) ($c->tarif['rawat_intensif'] ?? 0);
        $los = $c->los();

        if ($hari > 0 && $los !== null && $hari > $los) {
            $this->add(Severity::Kritis, 'Rawat intensif', 'Lama rawat intensif melebihi LOS',
                "Rawat intensif $hari hari, LOS $los hari.");
        }
        if ($biaya > 0 && $hari === 0) {
            $this->add(Severity::Perhatian, 'Rawat intensif', 'Ada biaya rawat intensif tanpa lama rawat intensif',
                'Isi jumlah hari rawat intensif atau periksa rincian biaya.');
        }
        if ($hari > 0 && $biaya <= 0) {
            $this->add(Severity::Perhatian, 'Rawat intensif', 'Lama rawat intensif diisi tanpa biaya rawat intensif',
                'Periksa rincian biaya.');
        }
        if ($c->ventilator_jam && $hari === 0) {
            $this->add(Severity::Perhatian, 'Rawat intensif', 'Jam ventilator diisi tanpa rawat intensif', 'Periksa field rawat intensif.');
        }
    }

    private function biayaVsBukti(Claim $c): void
    {
        $t = $c->tarif ?? [];
        $has = fn (DocumentType $d) => $c->documentsOfType($d)->isNotEmpty();
        $rp = fn (string $k) => Eklaim::rupiah($t[$k] ?? 0);

        if (($t['laboratorium'] ?? 0) > 0 && ! $has(DocumentType::Laboratorium)) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Biaya laboratorium tanpa hasil lab',
                "Tercatat {$rp('laboratorium')}. Unggah hasil laboratorium.");
        }
        if (($t['radiologi'] ?? 0) > 0 && ! $has(DocumentType::Radiologi)) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Biaya radiologi tanpa hasil radiologi',
                "Tercatat {$rp('radiologi')}. Unggah expertise radiologi.");
        }
        if (($t['prosedur_bedah'] ?? 0) > 0) {
            if (! $has(DocumentType::LaporanOperasi)) {
                $this->add(Severity::Kritis, 'Biaya vs bukti', 'Biaya prosedur bedah tanpa laporan operasi',
                    "Tercatat {$rp('prosedur_bedah')}. Unggah laporan operasi.");
            }
            if (! trim((string) $c->tindakan)) {
                $this->add(Severity::Kritis, 'Biaya vs bukti', 'Biaya prosedur bedah tanpa tindakan tercatat',
                    'Isi tindakan yang dilakukan agar bisa dikoding ICD-9-CM.');
            }
        }
        if (($t['pelayanan_darah'] ?? 0) > 0 && ! str_contains(mb_strtolower((string) $c->tindakan . ' ' . $c->diagnosis_sekunder), 'transfusi')) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Biaya pelayanan darah tanpa catatan transfusi',
                'Pastikan transfusi tercatat sebagai tindakan.');
        }
        if (($t['obat_kemoterapi'] ?? 0) > 0 && ! $has(DocumentType::DaftarObat)) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Biaya obat kemoterapi tanpa daftar obat', 'Unggah daftar obat/resep kemoterapi.');
        }
        if ($c->jenis_rawat === 'jalan' && (($t['kamar'] ?? 0) > 0 || ($t['rawat_intensif'] ?? 0) > 0)) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Biaya kamar pada rawat jalan', 'Periksa jenis rawat atau rincian biaya.');
        }
        if ($c->jenis_rawat === 'inap' && ($t['kamar'] ?? 0) <= 0 && $c->tarif !== null && $c->tarifTotal() > 0) {
            $this->add(Severity::Perhatian, 'Biaya vs bukti', 'Rawat inap tanpa biaya kamar', 'Periksa komponen tarif kamar/akomodasi.');
        }
    }
}
