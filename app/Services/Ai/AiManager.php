<?php

namespace App\Services\Ai;

use App\Models\AiProvider as ProviderConfig;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Providers\AnthropicProvider;
use App\Services\Ai\Providers\OpenAICompatibleProvider;

class AiManager
{
    public function for(ProviderConfig $config): AiProvider
    {
        return match ($config->driver) {
            'openai_compatible' => new OpenAICompatibleProvider($config),
            'anthropic' => new AnthropicProvider($config),
            default => throw new AiException("Driver AI tidak dikenal: {$config->driver}"),
        };
    }

    public function defaultConfig(): ProviderConfig
    {
        return ProviderConfig::default()
            ?? throw new AiException('Belum ada penyedia AI yang aktif. Atur di menu Admin → Penyedia AI.');
    }
}
