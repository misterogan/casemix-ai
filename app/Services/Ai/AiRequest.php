<?php

namespace App\Services\Ai;

final class AiRequest
{
    /** @var array<int, array{type: string, text?: string, media_type?: string, data?: string}> */
    public array $parts = [];

    public function __construct(
        public string $model,
        public string $system = '',
        public int $maxTokens = 4096,
        public float $temperature = 0.0,
    ) {}

    public function text(string $text): self
    {
        $this->parts[] = ['type' => 'text', 'text' => $text];

        return $this;
    }

    /** @param string $base64 isi gambar ter-encode base64 tanpa prefix data: */
    public function image(string $mediaType, string $base64): self
    {
        $this->parts[] = ['type' => 'image', 'media_type' => $mediaType, 'data' => $base64];

        return $this;
    }

    public function imageCount(): int
    {
        return count(array_filter($this->parts, fn ($p) => $p['type'] === 'image'));
    }
}
