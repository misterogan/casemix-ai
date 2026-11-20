<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pemakaian satu panggilan AI (token & perkiraan biaya). Dicatat hanya oleh AiManager. */
class AiUsage extends Model
{
    public const JENIS = ['analisa' => 'Analisa berkas', 'usulan_regulasi' => 'Usulan butir regulasi', 'draf_sanggahan' => 'Draf sanggahan'];

    protected $table = 'ai_usage';

    public const UPDATED_AT = null;

    protected $fillable = ['jenis', 'ref_id', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'biaya_usd', 'durasi_ms'];

    protected function casts(): array
    {
        return ['biaya_usd' => 'float', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'cache_read_tokens' => 'integer'];
    }

    /**
     * Harga [input, output] USD per 1 juta token dari config casemix.ai.harga (awalan nama model terpanjang), lalu casemix.budget.prices.
     * Tarif bertingkat: 'prompt_lebih_dari' => [batas token prompt, input, output] (mis. Haiku 5.5 di atas 100 ribu token).
     */
    public static function harga(?string $model, int $promptTokens = 0): ?array
    {
        if (! $model) {
            return null;
        }
        foreach ([config('casemix.ai.harga', []), config('casemix.budget.prices', [])] as $table) {
            uksort($table, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($table as $prefix => $p) {
                if (str_starts_with($model, $prefix)) {
                    if (isset($p['prompt_lebih_dari']) && $promptTokens > $p['prompt_lebih_dari'][0]) {
                        return [(float) $p['prompt_lebih_dari'][1], (float) $p['prompt_lebih_dari'][2]];
                    }

                    return [(float) $p[0], (float) $p[1]];
                }
            }
        }

        return null;
    }

    /** Biaya = token × harga model; token cache-read dihitung 10% harga input. Tingkat tarif dari ukuran prompt (input + cache-read). */
    public static function biaya(?string $model, int $in, int $out, int $cacheRead = 0): float
    {
        $h = self::harga($model, $in + $cacheRead);

        return $h ? round(($in * $h[0] + $out * $h[1] + $cacheRead * $h[0] * 0.1) / 1_000_000, 6) : 0.0;
    }

    /** SUM(biaya_usd) bulan berjalan menurut app.timezone (Asia/Jakarta). Satu-satunya angka pemakaian untuk anggaran. */
    public static function totalBulanIni(): float
    {
        return round((float) self::where('created_at', '>=', now()->startOfMonth())->sum('biaya_usd'), 6);
    }
}
