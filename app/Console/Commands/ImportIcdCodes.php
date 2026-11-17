<?php

namespace App\Console\Commands;

use App\Models\IcdCode;
use Illuminate\Console\Command;

/** Impor master ICD dari CSV: system,code,title,valid,accpdx,asterisk,valid_idrg. */
class ImportIcdCodes extends Command
{
    protected $signature = 'casemix:icd-import {file}';

    protected $description = 'Impor master kode ICD-10/ICD-9-CM (DHealth) dari CSV';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_readable($file) || ! ($h = fopen($file, 'r'))) {
            $this->error("File tidak terbaca: {$file}");

            return self::FAILURE;
        }
        $head = array_map(fn ($c) => strtolower(trim($c, " \t\xEF\xBB\xBF")), fgetcsv($h) ?: []);
        $bool = fn ($v) => ! in_array(strtoupper(trim((string) $v)), ['', '0', 'N', 'FALSE', 'TIDAK', 'NO'], true);
        $batch = [];
        $total = 0;
        $skip = 0;
        $flush = function () use (&$batch, &$total) {
            if ($batch) {
                IcdCode::upsert(array_values($batch), ['system', 'code'], ['title', 'valid', 'accpdx', 'asterisk', 'valid_idrg', 'updated_at']);
                $total += count($batch);
                $batch = [];
            }
        };
        while (($row = fgetcsv($h)) !== false) {
            $r = @array_combine($head, array_pad($row, count($head), null));
            $system = strtolower(trim((string) ($r['system'] ?? '')));
            $code = IcdCode::normalize((string) ($r['code'] ?? ''));
            if (! $r || ! in_array($system, ['icd10', 'icd9cm'], true) || $code === '') {
                $skip++;

                continue;
            }
            $now = now();
            $batch["{$system}|{$code}"] = ['system' => $system, 'code' => $code, 'title' => mb_substr(trim((string) ($r['title'] ?? '')), 0, 500),
                'valid' => $bool($r['valid'] ?? 1), 'accpdx' => $bool($r['accpdx'] ?? 1), 'asterisk' => $bool($r['asterisk'] ?? 0),
                'valid_idrg' => $bool($r['valid_idrg'] ?? 1), 'created_at' => $now, 'updated_at' => $now];
            if (count($batch) >= 1000) {
                $flush();
            }
        }
        $flush();
        fclose($h);
        $this->info("Diimpor: {$total} kode; dilewati: {$skip} baris.");

        return self::SUCCESS;
    }
}
