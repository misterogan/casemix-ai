<?php

namespace App\Services\Pnpk;

use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Teks per halaman PDF PNPK: pdftotext -layout (halaman dipisah form feed).
 * Halaman < 50 karakter dianggap scan → dirender pdftoppm lalu dibaca lewat jalur OCR AI (gambar) yang sama dengan folder.
 */
class PnpkTextExtractor
{
    public const MIN_CHARS = 50;

    public function __construct(private AiManager $ai) {}

    /** @return array<int, string> nomor halaman (mulai 1) => teks */
    public function pages(string $path): array
    {
        $out = [];
        foreach ($this->textPages($path) as $i => $teks) {
            $n = $i + 1;
            $teks = rtrim($teks);
            $out[$n] = mb_strlen(trim($teks)) < self::MIN_CHARS ? $this->ocr($path, $n) : $teks;
        }

        return $out;
    }

    /** @return string[] */
    public function textPages(string $path): array
    {
        $p = new Process([config('casemix.ai.pdftotext_binary', 'pdftotext'), '-layout', '-enc', 'UTF-8', $path, '-']);
        $p->setTimeout(300)->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('pdftotext gagal. Pastikan poppler-utils terpasang: ' . mb_substr(trim($p->getErrorOutput()), 0, 300));
        }
        $pages = explode("\f", $p->getOutput());
        if (count($pages) > 1 && trim(end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }

    /** OCR satu halaman: render PNG lalu minta AI menyalin teks apa adanya (tanpa menambah apa pun). */
    public function ocr(string $path, int $page): string
    {
        $tmp = storage_path('app/tmp/pnpk-' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists(dirname($tmp));
        $p = new Process([config('casemix.ai.pdftoppm_binary'), '-png', '-scale-to', (string) config('casemix.ai.render_max_px', 1300),
            '-f', (string) $page, '-l', (string) $page, $path, $tmp]);
        $p->setTimeout(120)->run();
        $files = glob($tmp . '*.png') ?: [];
        if (! $files) {
            return '';
        }
        $data = base64_encode(file_get_contents($files[0]));
        array_map('unlink', $files);

        $config = $this->ai->defaultConfig();
        $req = (new AiRequest($config->model_fast ?: $config->model_standard,
            'Anda alat OCR. Salin seluruh teks pada gambar halaman dokumen apa adanya, urut dari atas ke bawah. Jangan meringkas, menafsirkan, atau menambah apa pun.',
            min((int) $config->max_tokens, 8000), 0.0))
            ->image('image/png', $data)->text("Halaman $page. Tulis teksnya saja.");

        return trim($this->ai->for($config)->generate($req)->text);
    }
}
