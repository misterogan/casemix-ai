<?php

namespace App\Services\Ai;

use App\Models\AiProvider as ProviderConfig;
use App\Models\AiUsage;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAICompatibleProvider;

class AiManager
{
    /** Penyedia dibungkus: cek anggaran sebelum, catat ai_usage sesudah tiap panggilan (satu-satunya tempat pencatatan). */
    public function for(ProviderConfig $config): AiProvider
    {
        $inner = match ($config->driver) {
            'openai_compatible' => new OpenAICompatibleProvider($config),
            'anthropic' => new AnthropicProvider($config),
            default => throw new AiException("Driver AI tidak dikenal: {$config->driver}"),
        };

        return new class($inner) implements AiProvider
        {
            public function __construct(private AiProvider $inner) {}

            public function generate(AiRequest $request): AiResponse
            {
                app(AiBudget::class)->gate($request->jenis);
                $t = hrtime(true);
                $res = $this->inner->generate($request);
                $model = $res->model ?? $request->model;
                $in = (int) $res->tokensIn;
                $out = (int) $res->tokensOut;
                $cache = (int) $res->cacheReadTokens;
                AiUsage::create(['jenis' => $request->jenis, 'ref_id' => $request->refId, 'model' => $model,
                    'input_tokens' => $in, 'output_tokens' => $out, 'cache_read_tokens' => $cache,
                    'biaya_usd' => AiUsage::biaya($model, $in, $out, $cache), 'durasi_ms' => (int) ((hrtime(true) - $t) / 1_000_000)]);

                return $res;
            }

            public function ping(string $model): AiResponse
            {
                return $this->inner->ping($model);
            }
        };
    }

    public function defaultConfig(): ProviderConfig
    {
        return ProviderConfig::default()
            ?? throw new AiException('Belum ada penyedia AI yang aktif. Atur di menu Admin → Penyedia AI.');
    }

    /** Model untuk jenis tugas (casemix.ai.model_*); kosong = model standar penyedia. Model Claude hanya untuk driver anthropic. */
    public function model(string $jenis, ProviderConfig $config): string
    {
        $m = match ($jenis) {
            'usulan_regulasi' => config('casemix.ai.model_regulasi'),
            'draf_sanggahan' => config('casemix.ai.model_draf') ?: config('casemix.ai.model_analisa'),
            default => config('casemix.ai.model_analisa'),
        };
        if (! $m || ($config->driver !== 'anthropic' && str_starts_with($m, 'claude-'))) {
            return (string) $config->model_standard;
        }

        return $m;
    }
}
