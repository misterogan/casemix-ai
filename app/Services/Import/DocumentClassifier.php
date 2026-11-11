<?php

namespace App\Services\Import;

use App\Enums\DocumentType;
use App\Models\AiProvider as ProviderConfig;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use App\Services\Documents\DocumentReader;

/** Mengenali jenis dokumen dan identitas pasien satu file memakai model cepat. */
class DocumentClassifier
{
    public function __construct(private AiManager $ai, private DocumentReader $reader) {}

    /** @return array{jenis: string, nama: ?string, no_rm: ?string, no_sep: ?string, tanggal: ?string, keyakinan: string} */
    public function classify(string $absolutePath, string $mime, string $fileName, ProviderConfig $config): array
    {
        $content = $this->reader->read($absolutePath, $mime, maxPages: 1);
        $types = collect(DocumentType::cases())->map(fn ($t) => "{$t->value} = {$t->label()}")->implode('; ');

        $req = new AiRequest($config->model_fast, maxTokens: 400);
        $req->text("Kenali dokumen rumah sakit berikut (nama file: \"$fileName\").\n"
            . "Jenis yang mungkin: $types.\n"
            . 'Jawab HANYA JSON: {"jenis":"<kode jenis>","nama":"nama pasien atau null","no_rm":"nomor rekam medis atau null",'
            . '"no_sep":"nomor SEP 19 karakter atau null","tanggal":"tanggal pelayanan YYYY-MM-DD atau null","keyakinan":"tinggi|sedang|rendah"}');

        if ($content['text']) {
            $req->text(mb_substr($content['text'], 0, 4000));
        } else {
            foreach (array_slice($content['images'], 0, 1) as $img) {
                $req->image($img['media_type'], $img['data']);
            }
        }

        $data = $this->ai->for($config)->generate($req)->json();
        $jenis = DocumentType::tryFrom((string) ($data['jenis'] ?? ''))?->value ?? DocumentType::Tambahan->value;

        return [
            'jenis' => $jenis,
            'nama' => $this->nullable($data['nama'] ?? null),
            'no_rm' => $this->nullable($data['no_rm'] ?? null),
            'no_sep' => $this->nullable(isset($data['no_sep']) ? preg_replace('/\s+/', '', (string) $data['no_sep']) : null),
            'tanggal' => $this->nullable($data['tanggal'] ?? null),
            'keyakinan' => in_array($data['keyakinan'] ?? '', ['tinggi', 'sedang', 'rendah'], true) ? $data['keyakinan'] : 'rendah',
        ];
    }

    /** Kunci pengelompokan per pasien: SEP → No. RM → nama. */
    public static function groupKey(array $d): string
    {
        if (! empty($d['no_sep'])) {
            return 'sep:' . strtoupper($d['no_sep']);
        }
        if (! empty($d['no_rm'])) {
            return 'rm:' . ltrim(preg_replace('/\D/', '', $d['no_rm']), '0');
        }
        if (! empty($d['nama'])) {
            return 'nama:' . preg_replace('/[^a-z]/', '', mb_strtolower($d['nama']));
        }

        return 'tidak-dikenal';
    }

    private function nullable(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : null;

        return ($v === '' || mb_strtolower((string) $v) === 'null') ? null : $v;
    }
}
