<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\File;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Membaca isi dokumen untuk dikirim ke AI.
 * - PDF bertekstur teks  → teks (murah, cepat).
 * - PDF hasil scan       → beberapa halaman dirender ke PNG lewat pdftoppm (poppler-utils).
 * - Gambar (JPG/PNG/WebP)→ dikirim sebagai gambar.
 */
class DocumentReader
{
    /** Rata-rata karakter per halaman di bawah ini dianggap scan. */
    private const MIN_CHARS_PER_PAGE = 80;

    /**
     * @return array{text: ?string, images: array<int, array{media_type: string, data: string}>, pages: ?int, has_text: bool}
     */
    public function read(string $absolutePath, string $mime, ?int $maxPages = null): array
    {
        $maxPages ??= config('casemix.ai.max_pages_per_scan');

        if (str_starts_with($mime, 'image/')) {
            return ['text' => null, 'images' => [$this->imagePart($absolutePath, $mime)], 'pages' => 1, 'has_text' => false];
        }

        if ($mime !== 'application/pdf') {
            return ['text' => null, 'images' => [], 'pages' => null, 'has_text' => false];
        }

        [$text, $pages] = $this->pdfText($absolutePath);
        $hasText = $pages > 0 && mb_strlen(trim((string) $text)) / max($pages, 1) >= self::MIN_CHARS_PER_PAGE;

        if ($hasText) {
            return ['text' => $text, 'images' => [], 'pages' => $pages, 'has_text' => true];
        }

        return ['text' => null, 'images' => $this->renderPdf($absolutePath, $maxPages), 'pages' => $pages ?: null, 'has_text' => false];
    }

    /** @return array{0: ?string, 1: int} */
    public function pdfText(string $path): array
    {
        try {
            $pdf = (new Parser)->parseFile($path);
            $pages = count($pdf->getPages());
            $text = preg_replace("/[ \t]+/", ' ', $pdf->getText());

            return [trim((string) $text), $pages];
        } catch (Throwable) {
            return [null, 0];
        }
    }

    /** @return array<int, array{media_type: string, data: string}> */
    public function renderPdf(string $path, int $maxPages): array
    {
        $tmp = storage_path('app/tmp/render-' . bin2hex(random_bytes(6)));
        File::ensureDirectoryExists(dirname($tmp));

        $process = new Process([
            config('casemix.ai.pdftoppm_binary'), '-png',
            '-r', (string) config('casemix.ai.render_dpi'),
            '-f', '1', '-l', (string) $maxPages,
            $path, $tmp,
        ]);
        $process->setTimeout(120)->run();

        $files = glob($tmp . '*.png') ?: [];
        sort($files, SORT_NATURAL);

        $images = [];
        foreach ($files as $f) {
            $images[] = ['media_type' => 'image/png', 'data' => base64_encode(file_get_contents($f))];
            @unlink($f);
        }

        if ($images === [] && ! $process->isSuccessful()) {
            throw new \RuntimeException('Gagal merender PDF scan. Pastikan poppler-utils (pdftoppm) terpasang: ' . trim($process->getErrorOutput()));
        }

        return $images;
    }

    /** @return array{media_type: string, data: string} */
    private function imagePart(string $path, string $mime): array
    {
        return ['media_type' => $mime === 'image/jpg' ? 'image/jpeg' : $mime, 'data' => base64_encode(file_get_contents($path))];
    }
}
