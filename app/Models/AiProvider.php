<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProvider extends Model
{
    public const DRIVERS = [
        'openai_compatible' => 'OpenAI-compatible (OCTA, TokenKu.ai, OpenAI, vLLM, Ollama)',
        'anthropic' => 'Anthropic (Claude API langsung)',
    ];

    protected $fillable = [
        'name', 'driver', 'base_url', 'api_key', 'model_fast', 'model_standard',
        'supports_images', 'max_tokens', 'timeout', 'is_active', 'is_default',
        'data_location', 'notes', 'price_input_usd', 'price_output_usd',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'supports_images' => 'boolean',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_active', true)->orderByDesc('is_default')->first();
    }

    public function driverLabel(): string
    {
        return self::DRIVERS[$this->driver] ?? $this->driver;
    }

    public function maskedKey(): string
    {
        $k = (string) $this->api_key;

        return $k === '' ? '—' : str_repeat('•', 8) . substr($k, -4);
    }
}
