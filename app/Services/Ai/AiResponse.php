<?php

namespace App\Services\Ai;

final class AiResponse
{
    public function __construct(
        public string $text,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?string $model = null,
        public array $raw = [],
    ) {}

    /** Ambil objek JSON dari jawaban model (toleran terhadap ```json dan teks pembuka). */
    public function json(): array
    {
        $t = trim(preg_replace('/^```(?:json)?|```$/m', '', $this->text));
        $start = strpos($t, '{');
        $end = strrpos($t, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new AiException('Jawaban AI bukan JSON yang valid.');
        }
        $data = json_decode(substr($t, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new AiException('Jawaban AI bukan JSON yang valid: ' . json_last_error_msg());
        }

        return $data;
    }
}
