<?php

namespace App\Console\Commands;

use App\Models\FtpFolder;
use App\Services\Eklaim\EklaimClient;
use App\Services\Eklaim\EklaimException;
use App\Services\Eklaim\EklaimStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Sinkron status klaim dari E-Klaim (hanya get_claim_data). Tidak menulis apa pun ke E-Klaim. */
class EklaimSyncStatus extends Command
{
    protected $signature = 'casemix:eklaim-sync-status {--limit=50} {--hari=30} {--dry-run} {--sep=} {--tampil-field : Bersama --sep: tampilkan semua nama key respons, nilai hanya untuk key status/_cd/error/code}';

    protected $description = 'Baca status klaim (final/terkirim) dari E-Klaim untuk berkas yang sudah dianalisa (READ-ONLY)';

    public function handle(EklaimClient $client): int
    {
        $sep = trim((string) $this->option('sep'));
        if ($this->option('tampil-field') && $sep === '') {
            $this->error('--tampil-field hanya berlaku bersama --sep.');

            return self::FAILURE;
        }
        if ($sep === '' && ! EklaimClient::enabled()) {
            $this->info('Sinkron E-Klaim nonaktif (EKLAIM_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }
        $dry = (bool) $this->option('dry-run');

        if ($sep !== '') {
            return $this->satuSep($client, $sep, $dry);
        }

        $folders = FtpFolder::query()
            ->where('analysis_status', 'selesai')
            ->whereNotNull('no_sep')->where('no_sep', '!=', '')
            ->where(fn ($w) => $w->whereNull('eklaim_status')->orWhere('eklaim_status', '!=', EklaimStatus::TERKIRIM))
            ->where('analyzed_at', '>=', now()->subDays(max(1, (int) $this->option('hari'))))
            ->orderByRaw('eklaim_dicek_at IS NOT NULL')->orderBy('eklaim_dicek_at')->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $n = ['dicek' => 0, 'berubah' => 0, 'belum_ada' => 0, 'gagal' => 0];
        $beruntun = 0;
        $henti = false;
        foreach ($folders as $i => $f) {
            if ($i > 0) {
                usleep((int) config('services.eklaim.jeda_ms', 300) * 1000);
            }
            try {
                $data = $client->getClaimData($f->no_sep);
                $beruntun = 0;
                $n['dicek']++;
                $data === null && $n['belum_ada']++;
                $this->terapkan($f, $data, $dry) && $n['berubah']++;
            } catch (EklaimException $e) {
                $n['gagal']++;
                $dry || $f->forceFill(['eklaim_gagal_cek' => $f->eklaim_gagal_cek + 1])->save();
                if ($e->koneksi && ++$beruntun >= 3) {
                    $henti = true;
                    break;
                }
            }
        }

        $ringkas = "dicek {$n['dicek']}, berubah {$n['berubah']}, belum ada di E-Klaim {$n['belum_ada']}, gagal {$n['gagal']}"
            .($henti ? ' — dihentikan: 3 kegagalan koneksi berturut-turut' : '').($dry ? ' (dry-run)' : '');
        Log::channel('eklaim')->info('Sinkron status E-Klaim: '.$ringkas, $n + ['dihentikan' => $henti, 'dry_run' => $dry]);
        $this->info('E-Klaim: '.$ringkas.'.');

        return self::SUCCESS;
    }

    /** Uji satu SEP: tampilkan field status saja (bukan data pasien). */
    private function satuSep(EklaimClient $client, string $sep, bool $dry): int
    {
        try {
            $data = $client->getClaimData($sep);
        } catch (EklaimException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($data === null) {
            $this->warn('Klaim belum ada di E-Klaim.');
        } else {
            foreach (EklaimStatus::field($data) as $k => $v) {
                $this->line("$k: ".(is_scalar($v) ? $v : json_encode($v)));
            }
            if ($this->option('tampil-field')) {
                $this->tampilField($data);
            }
            $this->info('Status CERMAT: '.(EklaimStatus::dari($data) ?? 'belum final'));
        }
        if (! $dry) {
            FtpFolder::where('no_sep', $sep)->get()->each(fn ($f) => $this->terapkan($f, $data, false));
        }

        return self::SUCCESS;
    }

    /** Daftar nama key (bertingkat: a.b.c); nilai hanya untuk key yang namanya memuat status/_cd/error/code. */
    private function tampilField(array $data, string $awal = 'data'): void
    {
        foreach ($data as $k => $v) {
            $nama = "$awal.$k";
            if (is_array($v) && $v !== []) {
                $this->tampilField($v, $nama);

                continue;
            }
            $boleh = ! is_int($k) && preg_match('/status|_cd|error|code/i', (string) $k);
            $this->line('  '.$nama.($boleh ? ' = '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)) : ''));
        }
    }

    /** Simpan hasil cek; true bila status berubah. Status tidak pernah diturunkan dari 'terkirim'. */
    private function terapkan(FtpFolder $f, ?array $data, bool $dry): bool
    {
        $lama = $f->eklaim_status;
        $baru = $data === null ? $lama : EklaimStatus::dari($data);
        if ($lama === EklaimStatus::TERKIRIM) {
            $baru = $lama;
        }
        if ($dry) {
            return $baru !== $lama;
        }
        $f->forceFill([
            'eklaim_status' => $baru,
            'eklaim_dicek_at' => now(),
            'eklaim_gagal_cek' => 0,
            'eklaim_final_at' => $f->eklaim_final_at ?? ($baru ? now() : null),
            'eklaim_terkirim_at' => $f->eklaim_terkirim_at ?? ($baru === EklaimStatus::TERKIRIM ? now() : null),
        ])->save();

        return $baru !== $lama;
    }
}
