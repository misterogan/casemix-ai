<?php

namespace App\Console\Commands;

use App\Jobs\EkstrakPnpk;
use App\Models\AiUsage;
use App\Models\PnpkDokumen;
use App\Services\Pnpk\PnpkProposer;
use App\Services\Pnpk\PnpkTextExtractor;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Impor massal PDF PNPK dari folder (tidak rekursif). File asli disalin, bukan dipindah.
 * Nama baku: {slug}_{tahun}_KMK-{nomor}-{tahunkmk}.pdf; yang tidak cocok tetap diimpor dengan tanda perlu dilengkapi.
 */
class PnpkImport extends Command
{
    protected $signature = 'casemix:pnpk-import {path : Folder berisi PDF PNPK} {--dry-run : Tampilkan rencana tanpa menyimpan} {--hanya= : Glob nama file, mis. pneumonia*} {--estimasi : Perkiraan token & biaya usulan AI tanpa menyimpan apa pun}';

    protected $description = 'Impor PDF PNPK dari folder ke Acuan regulasi (ekstrak & usulan AI lewat antrean)';

    private const SINGKATAN = ['dm' => 'DM', 'ppok' => 'PPOK', 'hiv' => 'HIV', 'tb' => 'TB', 'ckd' => 'CKD', 'ispa' => 'ISPA', 'dbd' => 'DBD'];

    private const BULAN = ['januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6, 'juli' => 7,
        'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12];

    public function handle(PnpkTextExtractor $extractor): int
    {
        $dir = rtrim((string) $this->argument('path'), '/\\');
        if (! is_dir($dir)) {
            $this->error("Folder tidak ditemukan: $dir");

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $hanya = $this->option('hanya');
        $files = array_filter(glob($dir . DIRECTORY_SEPARATOR . '*') ?: [], fn ($f) => is_file($f) && strtolower(pathinfo($f, PATHINFO_EXTENSION)) === 'pdf'
            && (! $hanya || fnmatch($hanya, basename($f), FNM_CASEFOLD)));
        sort($files);
        if ($this->option('estimasi')) {
            return $this->estimasi($files, $extractor);
        }

        $rows = [];
        $n = $m = $k = 0;
        foreach ($files as $f) {
            $nama = basename($f);
            $meta = self::metaDariNama($nama);
            $status = 'baru';
            if (! self::isPdf($f)) {
                $status = 'bukan PDF';
            } elseif (PnpkDokumen::where('sha256', $sha = hash_file('sha256', $f))->exists()) {
                $status = 'lewati (sudah terdaftar)';
            }
            $rows[] = [$nama, $meta['judul'], $meta['tahun'], $meta['nomor_kmk'] ?? '—', $status];
            if ($status !== 'baru') {
                $m++;
                continue;
            }
            if ($dry) {
                $n++;
                $k += $meta['nomor_kmk'] ? 0 : 1;
                continue;
            }
            $tanggal = null;
            try {
                $tanggal = self::tanggalBerlaku($extractor->textPages($f));
            } catch (Throwable $e) {
                $this->warn("$nama: teks tidak terbaca untuk tanggal berlaku ({$e->getMessage()})");
            }
            $path = "pnpk/$sha.pdf";
            Storage::disk('local')->put($path, file_get_contents($f));
            $perlu = ! $meta['nomor_kmk'] || ! $tanggal;
            $d = PnpkDokumen::create(['judul' => $meta['judul'], 'nomor_kmk' => $meta['nomor_kmk'], 'tahun' => $meta['tahun'],
                'tanggal_berlaku' => $tanggal, 'status' => 'berlaku', 'path' => $path, 'sha256' => $sha,
                'status_proses' => 'antre', 'perlu_dilengkapi' => $perlu]);
            Audit::log('pnpk.impor', $d, "Mengimpor PNPK {$d->judul} dari folder", ['user' => 'console', 'file' => $nama, 'sha256' => $sha,
                'perlu_dilengkapi' => $perlu]);
            // EkstrakPnpk mengantrekan UsulkanButirPnpk setelah teks tersimpan (antrean biasa, tidak sinkron).
            EkstrakPnpk::dispatch($d->id);
            $n++;
            $k += $perlu ? 1 : 0;
        }

        $this->table(['File', 'Judul', 'Tahun', 'Nomor KMK', 'Status'], $rows);
        $this->info("$n diimpor, $m dilewati, $k perlu dilengkapi" . ($dry ? ' (dry-run, tidak disimpan)' : ''));

        return self::SUCCESS;
    }

    /** Hitung karakter pdftotext tiap PDF; token = karakter/3 + instruksi per potongan 15 halaman. Tidak menyimpan apa pun. */
    private function estimasi(array $files, PnpkTextExtractor $extractor): int
    {
        $model = (string) config('casemix.ai.model_regulasi', 'claude-haiku-5-5');
        $banding = 'claude-sonnet-5-5';
        $req = app(PnpkProposer::class)->request($model, 4096, 'Judul', []);
        $instruksi = (int) ceil((mb_strlen($req->system) + mb_strlen(collect($req->parts)->pluck('text')->implode(''))) / 3);
        $keluar = 1500; // perkiraan token jawaban per potongan
        $rows = [];
        $tot = ['token' => 0, 'out' => 0, 'a' => 0.0, 'b' => 0.0];
        foreach ($files as $f) {
            try {
                $pages = self::isPdf($f) ? $extractor->textPages($f) : [];
            } catch (Throwable $e) {
                $pages = [];
            }
            $hal = count($pages);
            $kar = array_sum(array_map(fn ($t) => mb_strlen((string) $t), $pages));
            $potong = max(1, (int) ceil($hal / PnpkProposer::CHUNK));
            $token = (int) ceil($kar / 3) + $potong * $instruksi;
            $out = $potong * $keluar;
            $a = AiUsage::biaya($model, $token, $out);
            $b = AiUsage::biaya($banding, $token, $out);
            $scan = $hal === 0 || $kar / $hal < 200;
            $rows[] = [basename($f), $hal, $kar, $token, '$' . number_format($a, 4), '$' . number_format($b, 4), $scan ? 'kemungkinan scan' : ''];
            $tot = ['token' => $tot['token'] + $token, 'out' => $tot['out'] + $out, 'a' => $tot['a'] + $a, 'b' => $tot['b'] + $b];
        }
        $this->table(['File', 'Halaman', 'Karakter', 'Token masuk', "Biaya $model", "Biaya $banding", 'Catatan'], $rows);
        $this->info(sprintf('Total %d token masuk, ±%d token keluar: US$%s (%s), US$%s (%s). Estimasi saja, tidak ada yang disimpan.',
            $tot['token'], $tot['out'], number_format($tot['a'], 4), $model, number_format($tot['b'], 4), $banding));

        return self::SUCCESS;
    }

    /** @return array{judul:string,tahun:int,nomor_kmk:?string} */
    public static function metaDariNama(string $nama): array
    {
        $base = preg_replace('/\.pdf$/i', '', $nama);
        if (preg_match('/^(.+?)_(\d{4})_KMK-(\d+)-(\d{4})$/i', $base, $x)) {
            return ['judul' => 'PNPK Tata Laksana ' . self::rapikan($x[1]), 'tahun' => (int) $x[2],
                'nomor_kmk' => "HK.01.07/MENKES/{$x[3]}/{$x[4]}"];
        }
        $tahun = preg_match('/(?<!\d)(19|20)\d{2}(?!\d)/', $base, $y) ? (int) $y[0] : (int) now()->year;
        $slug = trim(preg_replace('/(?<!\d)(19|20)\d{2}(?!\d)/', '', $base), " _-");

        return ['judul' => 'PNPK Tata Laksana ' . self::rapikan($slug !== '' ? $slug : $base), 'tahun' => $tahun, 'nomor_kmk' => null];
    }

    private static function rapikan(string $slug): string
    {
        $kata = preg_split('/[-_\s]+/', strtolower($slug), -1, PREG_SPLIT_NO_EMPTY);
        $kata = array_map(fn ($w) => self::SINGKATAN[$w] ?? $w, $kata);
        $teks = implode(' ', $kata);

        return mb_strtoupper(mb_substr($teks, 0, 1)) . mb_substr($teks, 1);
    }

    private static function isPdf(string $f): bool
    {
        $fh = @fopen($f, 'rb');
        if (! $fh) {
            return false;
        }
        $head = (string) fread($fh, 5);
        fclose($fh);

        return $head === '%PDF-';
    }

    /** Cari "Ditetapkan di Jakarta pada tanggal {d} {bulan} {yyyy}" di 5 halaman pertama & 5 terakhir. */
    public static function tanggalBerlaku(array $pages): ?string
    {
        $pages = array_values($pages);
        $pilih = count($pages) <= 10 ? $pages : array_merge(array_slice($pages, 0, 5), array_slice($pages, -5));
        $pola = '/Ditetapkan\s+di\s+Jakarta\s*,?\s*pada\s+tanggal\s*:?\s*(\d{1,2})\s+(' . implode('|', array_keys(self::BULAN)) . ')\s+(\d{4})/iu';
        foreach ($pilih as $teks) {
            if (preg_match($pola, (string) $teks, $x) && checkdate(self::BULAN[strtolower($x[2])], (int) $x[1], (int) $x[3])) {
                return sprintf('%04d-%02d-%02d', $x[3], self::BULAN[strtolower($x[2])], $x[1]);
            }
        }

        return null;
    }
}
