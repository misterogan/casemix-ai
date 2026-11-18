<?php

namespace App\Services\Vclaim;

use App\Models\VclaimHistori;
use App\Models\VclaimKlaim;
use App\Models\VclaimKunjungan;
use App\Models\VclaimSyncRun;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/** Histori pelayanan VClaim satu peserta (hanya GET), simpanan 24 jam, plus penanda readmisi/RS ini. */
class VclaimHistoriService
{
    public function __construct(private VclaimClient $client) {}

    /** @return array{from_cache: bool, fetched_at: Carbon, rows: \Illuminate\Support\Collection} */
    public function fetch(string $noKartu, string $mulai, string $akhir, bool $force = false): array
    {
        $last = $this->terakhir($noKartu);
        if (! $force && $last) {
            return ['from_cache' => true, 'fetched_at' => $last, 'rows' => $this->rows($noKartu, $mulai, $akhir)];
        }

        $t0 = hrtime(true);
        try {
            $data = $this->client->histori($noKartu, $mulai, $akhir);
        } catch (Throwable $e) {
            $this->log('gagal', 0, $e instanceof VclaimException ? $e->getMessage() : class_basename($e), $t0, $akhir);
            throw $e;
        }
        $now = now();
        foreach ($data as $row) {
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
                'fetched_at' => $now,
            ]);
        }
        $this->log('ok', count($data), null, $t0, $akhir);

        return ['from_cache' => false, 'fetched_at' => $now, 'rows' => $this->rows($noKartu, $mulai, $akhir)];
    }

    /** Waktu ambil terakhir bila < 24 jam. */
    public function terakhir(string $noKartu): ?Carbon
    {
        $max = VclaimHistori::where('no_kartu', $noKartu)->where('fetched_at', '>', now()->subDay())->max('fetched_at');

        return $max ? Carbon::parse($max) : null;
    }

    /** No kartu & tgl SEP dari data VClaim tersimpan (bukan dari input pengguna). */
    public static function sep(?string $noSep): array
    {
        if (! $noSep) {
            return [null, null];
        }
        $k = VclaimKlaim::where('no_sep', $noSep)->first(['no_kartu', 'tgl_sep']);
        $j = VclaimKunjungan::where('no_sep', $noSep)->first(['no_kartu', 'tgl_sep']);
        $tgl = $k?->tgl_sep ?? $j?->tgl_sep;

        return [$k?->no_kartu ?: ($j?->no_kartu ?: null), $tgl ? Carbon::parse($tgl)->startOfDay() : null];
    }

    /** Rentang ≤ 90 hari: akhir = tgl SEP (atau hari ini), mulai = akhir − 89 hari. */
    public static function rentang(?Carbon $tglSep): array
    {
        $akhir = ($tglSep ?? now())->copy()->startOfDay();

        return [$akhir->copy()->subDays(89)->toDateString(), $akhir->toDateString()];
    }

    /** Payload untuk halaman: hanya bila tersimpan < 24 jam (tanpa memanggil BPJS). */
    public function simpanan(?string $noSep): ?array
    {
        [$noKartu, $tglSep] = self::sep($noSep);
        if (! $noKartu || ! ($last = $this->terakhir($noKartu))) {
            return null;
        }
        [$mulai, $akhir] = self::rentang($tglSep);

        return $this->payload(['from_cache' => true, 'fetched_at' => $last, 'rows' => $this->rows($noKartu, $mulai, $akhir)], $noSep, $tglSep, $mulai, $akhir);
    }

    /** Jawaban JSON tombol "Ambil riwayat pelayanan". */
    public function jawab(Request $request, ?string $noSep, ?Model $subject, array $props): JsonResponse
    {
        if (! VclaimClient::enabled()) {
            return self::gagal('Bridging VClaim belum diaktifkan.', 409);
        }
        [$noKartu, $tglSep] = self::sep($noSep);
        if (! $noKartu) {
            return self::gagal('Nomor kartu peserta belum diketahui untuk SEP ini.', 422);
        }
        $force = $request->boolean('refresh');
        if ($force) {
            $key = 'histori-refresh:'.$request->user()->id.':'.$noSep;
            if (RateLimiter::tooManyAttempts($key, 1)) {
                return self::gagal('Riwayat baru saja diperbarui. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.', 429);
            }
            RateLimiter::hit($key, 60);
        }
        [$mulai, $akhir] = self::rentang($tglSep);
        try {
            $res = $this->fetch($noKartu, $mulai, $akhir, $force);
        } catch (Throwable $e) {
            if (! $e instanceof VclaimException) {
                report($e);
            }
            $pesan = $e instanceof VclaimException ? mb_strimwidth($e->getMessage(), 0, 120, '…') : 'gangguan koneksi';

            return self::gagal("BPJS tidak merespons: {$pesan}. Coba lagi beberapa saat.", 502);
        }
        $out = $this->payload($res, $noSep, $tglSep, $mulai, $akhir);
        Audit::log('vclaim.riwayat.ambil', $subject, 'Mengambil riwayat pelayanan peserta dari VClaim',
            $props + ['from_cache' => $res['from_cache'], 'jumlah' => count($out['items'])]);

        return response()->json(['ok' => true] + $out);
    }

    public function payload(array $res, ?string $noSep, ?Carbon $tglSep, string $mulai, string $akhir): array
    {
        $ppk = substr((string) $noSep, 0, 8);
        $nama = mb_strtolower(trim((string) config('casemix.vclaim.ppk_nama')));
        $items = $res['rows']->map(function (VclaimHistori $r) use ($noSep, $tglSep, $ppk, $nama) {
            $inap = self::inap($r->jns_pelayanan);
            $p = mb_strtolower((string) $r->ppk_pelayanan);

            return [
                'no_sep' => $r->no_sep,
                'tgl_sep' => $r->tgl_sep?->toDateString(),
                'tgl_plg_sep' => $r->tgl_plg_sep?->toDateString(),
                'jns_pelayanan' => $inap ? 'Rawat inap' : 'Rawat jalan',
                'kelas_rawat' => $r->kelas_rawat,
                'ppk_pelayanan' => $r->ppk_pelayanan,
                'poli' => $r->poli,
                'diagnosa' => $r->diagnosa,
                'sep_ini' => $r->no_sep === $noSep,
                'rs_ini' => $p !== '' && (($nama !== '' && str_contains($p, $nama)) || ($ppk !== '' && str_contains($p, mb_strtolower($ppk)))),
                'readmisi' => (bool) ($tglSep && $r->no_sep !== $noSep && $r->tgl_plg_sep && $inap
                    && str_starts_with($r->no_sep, $ppk)
                    && $r->tgl_plg_sep->lte($tglSep) && $r->tgl_plg_sep->gte($tglSep->copy()->subDays(30))),
            ];
        })->values()->all();
        $c = collect($items);

        return [
            'from_cache' => $res['from_cache'],
            'fetched_at' => $res['fetched_at']->toIso8601String(),
            'fetched_label' => $res['fetched_at']->copy()->locale('id')->translatedFormat('j M Y H.i'),
            'rentang' => ['mulai' => $mulai, 'akhir' => $akhir],
            'ringkasan' => ['total' => $c->count(), 'inap' => $c->where('jns_pelayanan', 'Rawat inap')->count(),
                'jalan' => $c->where('jns_pelayanan', 'Rawat jalan')->count(), 'readmisi' => $c->where('readmisi', true)->count()],
            'items' => $items,
        ];
    }

    public static function inap(?string $jns): bool
    {
        return $jns === '1' || str_contains(strtolower((string) $jns), 'inap');
    }

    private function rows(string $noKartu, string $mulai, string $akhir)
    {
        return VclaimHistori::where('no_kartu', $noKartu)->whereBetween('tgl_sep', [$mulai, $akhir.' 23:59:59'])
            ->orderByDesc('tgl_sep')->orderByDesc('id')->get();
    }

    private static function gagal(string $msg, int $code): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $msg], $code);
    }

    private static function date(?string $v): ?string
    {
        return $v ? Carbon::parse($v)->toDateString() : null;
    }

    private function log(string $status, int $n, ?string $err, int $t0, string $tgl): void
    {
        VclaimSyncRun::create([
            'jenis' => 'histori', 'tanggal' => $tgl, 'jns' => null, 'status' => $status, 'jumlah' => $n,
            'error' => $err ? mb_substr($err, 0, 500) : null,
            'durasi_ms' => (int) ((hrtime(true) - $t0) / 1_000_000),
        ]);
    }
}
