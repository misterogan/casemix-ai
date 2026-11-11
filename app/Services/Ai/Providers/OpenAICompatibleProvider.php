<?php

namespace App\Services\Ai\Providers;

use App\Models\AiProvider as ProviderConfig;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Untuk semua penyedia berformat OpenAI Chat Completions:
 * agregator lokal (Telkomsel OCTA, XLSMART TokenKu.ai), OpenAI, Gemini (endpoint OpenAI-compatible),
 * serta model terbuka di server sendiri (vLLM, Ollama, LM Studio).
 */
class OpenAICompatibleProvider implements AiProvider
{
    public function __construct(private ProviderConfig $config) {}

    public function generate(AiRequest $request): AiResponse
    {
        if ($request->imageCount() > 0 && ! $this->config->supports_images) {
            throw new AiException("Model di penyedia {$this->config->name} ditandai tidak bisa membaca gambar/scan.");
        }

        $content = array_map(fn ($p) => $p['type'] === 'text'
            ? ['type' => 'text', 'text' => $p['text']]
            : ['type' => 'image_url', 'image_url' => ['url' => "data:{$p['media_type']};base64,{$p['data']}"]],
            $request->parts);

        $messages = [];
        if ($request->system !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->system];
        }
        $messages[] = ['role' => 'user', 'content' => $content];

        $url = rtrim((string) $this->config->base_url, '/') . '/chat/completions';

        try {
            $res = Http::withToken((string) $this->config->api_key)
                ->acceptJson()
                ->timeout($this->config->timeout)
                ->retry(2, 2000, throw: false)
                ->post($url, [
                    'model' => $request->model,
                    'messages' => $messages,
                    'max_tokens' => $request->maxTokens,
                    'temperature' => $request->temperature,
                ]);
        } catch (ConnectionException $e) {
            throw new AiException("Tidak bisa terhubung ke {$this->config->name}: {$e->getMessage()}");
        }

        if ($res->failed()) {
            $msg = $res->json('error.message') ?? mb_substr($res->body(), 0, 300);
            throw new AiException("{$this->config->name} menolak permintaan (HTTP {$res->status()}): $msg");
        }

        $text = $res->json('choices.0.message.content');
        if (is_array($text)) { // beberapa penyedia mengembalikan array part
            $text = collect($text)->pluck('text')->implode('');
        }

        return new AiResponse(
            text: (string) $text,
            tokensIn: $res->json('usage.prompt_tokens'),
            tokensOut: $res->json('usage.completion_tokens'),
            model: $res->json('model') ?? $request->model,
            raw: ['id' => $res->json('id')],
        );
    }

    public function ping(string $model): AiResponse
    {
        return $this->generate((new AiRequest($model, maxTokens: 20))->text('Balas dengan satu kata: siap'));
    }
}
