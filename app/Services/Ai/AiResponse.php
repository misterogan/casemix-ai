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
            throw new AiException('Jawaban AI bukan JSON yang valid.' . $this->hint());
        }
        $data = json_decode(substr($t, $start, $end - $start + 1), true);
        if (! is_array($data)) {
            throw new AiException('Jawaban AI bukan JSON yang valid: ' . json_last_error_msg() . '.' . $this->hint());
        }

        return $data;
    }

    private function hint(): string
    {
        $stop = $this->raw['stop_reason'] ?? null;
        if (in_array($stop, ['max_tokens', 'length'], true)) {
            return " Jawaban terpotong karena batas Max Tokens ({$this->tokensOut} token) tercapai; naikkan Max Tokens di pengaturan penyedia AI.";
        }
        if ($stop === 'refusal') {
            return ' Model menolak menjawab.';
        }
        if (trim($this->text) === '') {
            return ' Jawaban kosong' . ($stop ? " (stop_reason: $stop)" : '') . '.';
        }

        return $stop ? " (stop_reason: $stop)" : '';
    }
}
