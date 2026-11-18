<?php

namespace App\Services\Vclaim;

use App\Models\VclaimHistori;
use App\Models\VclaimKlaim;
use App\Models\VclaimKlaimStatusHistory;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use Carbon\Carbon;
use Throwable;

/** Menyimpan hasil Monitoring VClaim ke tabel lokal (hanya membaca dari BPJS). */
class VclaimSync
{
    public function __construct(private VclaimClient $client) {}

    /** @return array{jumlah:int, gagal:int} */
    public function klaim(Carbon $from, Carbon $to): array
    {
        $jumlah = $gagal = 0;
        foreach ($this->days($from, $to) as $tgl) {
            foreach ([1, 2] as $jns) {
                foreach ([1, 2, 3] as $status) {
                    $r = $this->run('klaim', $tgl, (string) $jns, function () use ($tgl, $jns, $status) {
                        $rows = $this->client->klaim($tgl, $jns, $status);
                        foreach ($rows as $row) {
                            $this->upsertKlaim($row, $status, $jns);
                        }

                        return count($rows);
                    });
                    $r === null ? $gagal++ : $jumlah += $r;
                }
            }
        }

        return compact('jumlah', 'gagal');
    }

    /** @return array{jumlah:int, gagal:int} */
    public function kunjungan(Carbon $from, Carbon $to): array
    {
        $jumlah = $gagal = 0;
        foreach ($this->days($from, $to) as $tgl) {
            foreach ([1, 2] as $jns) {
                $r = $this->run('kunjungan', $tgl, (string) $jns, function () use ($tgl, $jns) {
                    $rows = $this->client->kunjungan($tgl, $jns);
                    foreach ($rows as $row) {
                        if (empty($row['noSep'])) {
                            continue;
                        }
                        VclaimKunjungan::updateOrCreate(['no_sep' => $row['noSep']], [
                            'tgl_sep' => self::date($row['tglSep'] ?? null),
                            'tgl_plg_sep' => self::date($row['tglPlgSep'] ?? null),
                            'jns_pelayanan' => $row['jnsPelayanan'] ?? null,
                            'kelas_rawat' => $row['kelasRawat'] ?? null,
                            'poli' => $row['poli'] ?? null,
                            'diagnosa' => isset($row['diagnosa']) ? mb_substr((string) $row['diagnosa'], 0, 255) : null,
                            'no_kartu' => $row['noKartu'] ?? null,
                            'no_rujukan' => $row['noRujukan'] ?? null,
                            'nama' => $row['nama'] ?? null,
                            'synced_at' => now(),
                        ]);
                    }

                    return count($rows);
                });
                $r === null ? $gagal++ : $jumlah += $r;
            }
        }

        return compact('jumlah', 'gagal');
    }

    /** Histori pelayanan 90 hari terakhir; dilewati bila diambil < 24 jam lalu. */
    public function histori(string $noKartu): ?int
    {
        $fresh = VclaimHistori::where('no_kartu', $noKartu)->where('fetched_at', '>', now()->subDay())->exists();
        if ($fresh) {
            return 0;
        }

        $akhir = now()->toDateString();
        $mulai = now()->subDays(90)->toDateString();

        return $this->run('histori', $akhir, null, function () use ($noKartu, $mulai, $akhir) {
            $rows = $this->client->histori($noKartu, $mulai, $akhir);
            foreach ($rows as $row) {
                if (empty($row['noSep'])) {
                    continue;
                }
                VclaimHistori::updateOrCreate(['no_kartu' => $noKartu, 'no_sep' => $row['noSep']], [
                    'tgl_sep' => self::date($row['tglSep'] ?? null),
                    'tgl_plg_sep' => self::date($row['tglPlgSep'] ?? null),
                    'jns_pelayanan' => $row['jnsPelayanan'] ?? null,
                    'kelas_rawat' => $row['kelasRawat'] ?? null,
                    'diagnosa' => $row['diagnosa'] ?? null,
                    'ppk_pelayanan' => $row['ppkPelayanan'] ?? null,
                    'poli' => $row['poli'] ?? null,
                    'no_rujukan' => $row['noRujukan'] ?? null,
                    'fetched_at' => now(),
                ]);
            }

            return count($rows);
        });
    }

    public function upsertKlaim(array $row, int $status, ?int $jns = null): void
    {
        $noSep = $row['noSEP'] ?? $row['noSep'] ?? null;
        if (! $noSep) {
            return;
        }
        $now = now();
        $by = $row['biaya'] ?? [];
        $peserta = $row['peserta'] ?? [];
        $cbg = $row['Inacbg'] ?? $row['inacbg'] ?? [];

        $klaim = VclaimKlaim::firstOrNew(['no_sep' => $noSep]);
        $lama = $klaim->exists ? (int) $klaim->status_kode : null;
        $katLama = $klaim->exists ? $klaim->status_kategori : null;

        $klaim->fill([
            'status_kode' => $status,
            'status_teks' => self::statusTeks($row['status'] ?? null) ?? VclaimKlaim::STATUS[$status],
            'tgl_sep' => self::date($row['tglSep'] ?? null),
            'tgl_pulang' => self::date($row['tglPulang'] ?? null),
            'jns_pelayanan' => $jns !== null ? (string) $jns : self::kosong($row['jnsPelayanan'] ?? null),
            'kelas_rawat' => self::kosong($row['kelasRawat'] ?? null),
            'poli' => self::kosong($row['poli'] ?? null),
            'no_fpk' => self::kosong($row['noFPK'] ?? null),
            'inacbg_kode' => self::kosong($cbg['kode'] ?? null),
            'inacbg_nama' => self::kosong($cbg['nama'] ?? null),
            'by_pengajuan' => self::money($by['byPengajuan'] ?? null),
            'by_setujui' => self::money($by['bySetujui'] ?? null),
            'by_tarif_gruper' => self::money($by['byTarifGruper'] ?? null),
            'by_tarif_rs' => self::money($by['byTarifRS'] ?? null),
            'by_topup' => self::money($by['byTopup'] ?? null),
            'no_kartu' => $peserta['noKartu'] ?? null,
            'no_mr' => $peserta['noMR'] ?? null,
            'nama' => $peserta['nama'] ?? null,
            'synced_at' => $now,
        ]);
        $kat = VclaimKlaim::kategori($klaim->status_teks, $status);
        $klaim->status_kategori = $kat;
        if (! $klaim->exists) {
            $klaim->first_seen_at = $now;
            $klaim->status_changed_at = $now;
        } elseif ($lama !== $status || $katLama !== $kat) {
            $klaim->status_changed_at = $now;
            VclaimKlaimStatusHistory::create([
                'no_sep' => $noSep, 'status_lama' => $lama, 'status_baru' => $status,
                'kategori_lama' => $katLama, 'kategori_baru' => $kat, 'terlihat_at' => $now,
            ]);
        }
        $klaim->save();
    }

    /** "", "-", "null" dianggap kosong. */
    public static function kosong(mixed $v): ?string
    {
        $v = $v === null ? null : trim((string) $v);

        return in_array($v, [null, '', '-'], true) || strtolower($v) === 'null' ? null : $v;
    }

    /** "2#Klaim Pending" -> "Klaim Pending". */
    public static function statusTeks(mixed $v): ?string
    {
        $v = self::kosong($v);

        return $v !== null && preg_match('/^\d+#(.+)$/', $v, $m) ? trim($m[1]) : $v;
    }

    public static function money(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return number_format((float) $v, 2, '.', '');
        }
        $s = trim((string) $v);
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) { // format ribuan Indonesia: 1.400.000,50
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        $s = preg_replace('/[^0-9.\-]/', '', $s);

        return is_numeric($s) ? number_format((float) $s, 2, '.', '') : null;
    }

    private static function date(?string $v): ?string
    {
        if (! $v || str_starts_with($v, '0000')) {
            return null;
        }
        try {
            return Carbon::parse($v)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function days(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $out[] = $d->toDateString();
        }

        return $out;
    }

    /** Menjalankan satu panggilan dan mencatat vclaim_sync_runs. Null bila gagal. */
    private function run(string $jenis, string $tgl, ?string $jns, callable $fn): ?int
    {
        $t0 = hrtime(true);
        try {
            $n = $fn();
            $this->log($jenis, $tgl, $jns, 'ok', $n, null, $t0);

            return $n;
        } catch (Throwable $e) {
            $this->log($jenis, $tgl, $jns, 'gagal', 0, $e instanceof VclaimException || $e instanceof \InvalidArgumentException
                ? $e->getMessage() : class_basename($e), $t0);

            return null;
        }
    }

    private function log(string $jenis, string $tgl, ?string $jns, string $status, int $n, ?string $err, int $t0): void
    {
        VclaimSyncRun::create([
            'jenis' => $jenis, 'tanggal' => $tgl, 'jns' => $jns, 'status' => $status, 'jumlah' => $n,
            'error' => $err ? mb_substr($err, 0, 500) : null,
            'durasi_ms' => (int) ((hrtime(true) - $t0) / 1_000_000),
        ]);
    }
}
