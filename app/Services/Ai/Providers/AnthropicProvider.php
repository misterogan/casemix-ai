<?php

namespace App\Services\Ai\Providers;

use App\Models\AiProvider as ProviderConfig;
use App\Services\Ai\AiException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Claude API langsung (Anthropic Messages API). */
class AnthropicProvider implements AiProvider
{
    private const NO_THINKING_KEY = 'ai:tanpa-thinking:';

    public function __construct(private ProviderConfig $config) {}

    public function generate(AiRequest $request): AiResponse
    {
        $content = array_map(fn ($p) => $p['type'] === 'text'
            ? ['type' => 'text', 'text' => $p['text']]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $p['media_type'], 'data' => $p['data']]],
            $request->parts);

        $payload = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        if ($request->system !== '') {
            $payload['system'] = $request->system;
        }

        $extra = $this->thinkingParams($request->model);
        $res = $this->send($payload + $extra);
        // Model yang tidak mengenal parameter berpikir: ulangi SEKALI tanpa parameter itu dan ingat modelnya
        if ($extra && $res->status() === 400 && preg_match('/thinking|effort|output_config/i', (string) $res->json('error.message', $res->body()))) {
            Cache::put(self::NO_THINKING_KEY . $request->model, true, now()->addDays(30));
            $res = $this->send($payload);
        }

        if ($res->failed()) {
            $msg = $res->json('error.message') ?? mb_substr($res->body(), 0, 300);
            throw new AiException("{$this->config->name} menolak permintaan (HTTP {$res->status()}): $msg");
        }

        $blocks = collect($res->json('content', []));
        $text = $blocks->where('type', 'text')->pluck('text')->implode('');

        return new AiResponse(
            text: $text,
            tokensIn: $res->json('usage.input_tokens'),
            tokensOut: $res->json('usage.output_tokens'),
            model: $res->json('model') ?? $request->model,
            raw: [
                'id' => $res->json('id'),
                'stop_reason' => $res->json('stop_reason'),
                'block_types' => $blocks->pluck('type')->unique()->values()->all(),
                'text_length' => mb_strlen($text),
            ],
        );
    }

    /** Kurangi "berpikir" supaya token keluaran dipakai untuk jawaban: off = thinking dimatikan, low = effort rendah. */
    private function thinkingParams(string $model): array
    {
        if (Cache::get(self::NO_THINKING_KEY . $model)) {
            return [];
        }

        return match (config('casemix.ai.thinking', 'off')) {
            'off' => ['thinking' => ['type' => 'disabled']],
            'low' => ['output_config' => ['effort' => 'low']],
            default => [],
        };
    }

    private function send(array $payload): Response
    {
        $base = rtrim($this->config->base_url ?: 'https://api.anthropic.com', '/');

        try {
            return Http::withHeaders([
                'x-api-key' => (string) $this->config->api_key,
                'anthropic-version' => '2023-06-01',
            ])->acceptJson()
                ->timeout($this->config->timeout)
                ->retry(2, 2000, fn ($e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false)
                ->post("$base/v1/messages", $payload);
        } catch (ConnectionException $e) {
            throw new AiException("Tidak bisa terhubung ke {$this->config->name}: {$e->getMessage()}");
        }
    }

    public function ping(string $model): AiResponse
    {
        return $this->generate((new AiRequest($model, maxTokens: 20))->text('Balas dengan satu kata: siap'));
    }
}
