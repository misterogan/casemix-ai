<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sekali jalan: geser kolom *_at buatan aplikasi +7 jam (data lama tersimpan UTC sebelum app.timezone = Asia/Jakarta).
 * Kolom yang bersumber dari FTP/VClaim/Enigma dan tabel sistem Laravel dikecualikan. Ditandai di app_settings agar tidak ganda.
 */
class GeserZonaWaktu extends Command
{
    protected $signature = 'casemix:geser-zona-waktu {--dry-run : Hanya tampilkan tabel/kolom dan jumlah baris}
        {--sebelum= : Batas UTC "YYYY-MM-DD HH:MM:SS"; hanya baris dengan created_at sebelum ini yang digeser (wajib bila bukan dry-run)}';

    protected $description = 'Geser timestamp *_at buatan aplikasi dari UTC ke WIB (+7 jam), sekali jalan';

    public const PENANDA = 'tz_geser_wib_selesai';

    /** Tabel sistem/antrian: kolom *_at bertipe integer atau diisi DB (useCurrent). */
    private const TABEL_LEWATI = ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens'];

    /** Kolom bersumber dari luar aplikasi (tanggal FTP MLSD). */
    private const KOLOM_LEWATI = ['ftp_folders.files_modified_at'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if (! $dry && DB::table('app_settings')->where('key', self::PENANDA)->exists()) {
            $this->error('Sudah pernah dijalankan (app_settings.' . self::PENANDA . '). Dibatalkan.');

            return self::FAILURE;
        }

        $sebelum = $this->option('sebelum');
        if ($sebelum === null && ! $dry) {
            $this->error('Opsi --sebelum="YYYY-MM-DD HH:MM:SS" (UTC) wajib bila tidak --dry-run.');

            return self::FAILURE;
        }
        if ($sebelum !== null && ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sebelum)) {
            $this->error('Format --sebelum harus "YYYY-MM-DD HH:MM:SS".');

            return self::FAILURE;
        }

        $daftar = $this->kolom();
        $baris = [];
        foreach ($daftar as [$tabel, $kolom]) {
            $baris[] = [$tabel, $kolom, $this->saring(DB::table($tabel), $tabel, $kolom, $sebelum)->whereNotNull($kolom)->count()];
        }
        $this->table(['Tabel', 'Kolom', 'Baris terisi'], $baris);

        if ($dry) {
            $this->info('Dry-run: tidak ada perubahan. Dikecualikan: ' . implode(', ', self::KOLOM_LEWATI) . '.');

            return self::SUCCESS;
        }

        $mysql = DB::getDriverName() !== 'sqlite';
        $perTabel = [];
        foreach ($daftar as [$tabel, $kolom]) {
            $perTabel[$tabel][] = $kolom;
        }
        DB::transaction(function () use ($perTabel, $mysql, $sebelum) {
            // Satu UPDATE per tabel: WHERE created_at dievaluasi sebelum kolom digeser.
            foreach ($perTabel as $tabel => $kolomList) {
                $set = [];
                foreach ($kolomList as $kolom) {
                    $q = DB::getQueryGrammar()->wrap($kolom);
                    $set[$kolom] = DB::raw("CASE WHEN $q IS NULL THEN NULL ELSE " . ($mysql ? "DATE_ADD($q, INTERVAL 7 HOUR)" : "datetime($q, '+7 hours')") . ' END');
                }
                $this->saring(DB::table($tabel), $tabel, $kolomList[0], $sebelum)->update($set);
            }
            DB::table('app_settings')->insert(['key' => self::PENANDA, 'value' => json_encode(now()->toDateTimeString()), 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->info('Selesai: ' . count($daftar) . ' kolom digeser +7 jam.');

        return self::SUCCESS;
    }

    /** Batasi ke baris dengan created_at < $sebelum (tabel tanpa created_at: pakai kolom itu sendiri). */
    private function saring($query, string $tabel, string $kolom, ?string $sebelum)
    {
        if ($sebelum === null) {
            return $query;
        }
        $acuan = Schema::hasColumn($tabel, 'created_at') ? 'created_at' : $kolom;

        return $query->where($acuan, '<', $sebelum);
    }

    /** @return array<int, array{0:string,1:string}> */
    public function kolom(): array
    {
        $hasil = [];
        foreach (Schema::getTableListing(DB::getDriverName() === 'sqlite' ? null : DB::getDatabaseName(), false) as $tabel) {
            if (in_array($tabel, self::TABEL_LEWATI, true)) {
                continue;
            }
            foreach (Schema::getColumns($tabel) as $c) {
                $tipe = strtolower($c['type_name']);
                if (str_ends_with($c['name'], '_at') && (str_contains($tipe, 'timestamp') || str_contains($tipe, 'datetime'))
                    && ! in_array("$tabel.{$c['name']}", self::KOLOM_LEWATI, true)) {
                    $hasil[] = [$tabel, $c['name']];
                }
            }
        }

        return $hasil;
    }
}
