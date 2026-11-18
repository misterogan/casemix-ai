<?php

namespace App\Services\Regulasi;

use App\Models\RegulasiButir;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiException;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiRequest;
use App\Services\Pnpk\PnpkAcuan;
use App\Support\IcdGuard;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Draf sanggahan (model_draf, ai_usage jenis draf_sanggahan, patuh AiBudget). Hanya butir diperiksa_manusia yang
 * ditampilkan di panel DAN dikirim dengan id; rujukan id lain dibuang; tiap paragraf berakhir sitasi.
 */
class DrafSanggahan
{
    public const TANPA_ACUAN = 'Belum ada acuan terkonfirmasi untuk kasus ini';

    public function __construct(private AiManager $ai, private AiBudget $budget, private PnpkAcuan $acuan) {}

    /**
     * @param Collection<int, RegulasiButir> $ditampilkan butir panel Dasar sanggahan
     * @param int[] $diminta id yang dikirim halaman
     * @return array{teks: string, butir: int[]}
     */
    public function susun(Collection $ditampilkan, array $diminta, array $konteks, ?int $refId): array
    {
        $diminta = array_map('intval', $diminta);
        $kirim = $ditampilkan->filter(fn (RegulasiButir $b) => $b->status === 'disetujui' && $b->diperiksa_manusia && in_array($b->id, $diminta, true))
            ->keyBy('id');
        if ($kirim->isEmpty()) {
            throw ValidationException::withMessages(['butir' => self::TANPA_ACUAN]);
        }
        if ($blok = $this->budget->startBlocker()) {
            throw ValidationException::withMessages(['butir' => $blok]);
        }
        try {
            $config = $this->ai->defaultConfig();
            $req = new AiRequest($this->ai->model('draf_sanggahan', $config), $this->system(), 2048, 0.0, 'draf_sanggahan', $refId);
            $req->text("Data kasus (tanpa identitas pasien):\n" . json_encode($konteks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\nBUTIR ACUAN (rujuk HANYA lewat id):\n" . json_encode($kirim->map(fn ($b) => $this->acuan->baris($b))->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                . "\n\nJawab HANYA satu objek JSON tanpa ```: {\"paragraf\":[{\"teks\":\"satu paragraf\",\"sitasi\":[1]}]}");
            $json = $this->ai->for($config)->generate($req)->json();
        } catch (AiException $e) {
            throw ValidationException::withMessages(['butir' => $e->getMessage()]);
        }

        $paragraf = [];
        $dipakai = [];
        foreach (is_array($json['paragraf'] ?? null) ? $json['paragraf'] : [] as $p) {
            $ids = collect(is_array($p['sitasi'] ?? null) ? $p['sitasi'] : [])->filter(fn ($i) => is_numeric($i))->map(fn ($i) => (int) $i)
                ->filter(fn ($i) => $kirim->has($i))->unique()->values();
            $teks = is_string($p['teks'] ?? null) ? trim(preg_replace('/\[(?:id\s*)?\d+(?:\s*[,;]\s*\d+)*\]/iu', '', IcdGuard::strip($p['teks']))) : '';
            if ($ids->isEmpty() || $teks === '') {
                continue; // paragraf tanpa sitasi sah dibuang
            }
            $paragraf[] = $teks . ' [' . $ids->map(fn ($i) => $kirim[$i]->sitasi())->implode('; ') . ']';
            $dipakai = array_merge($dipakai, $ids->all());
        }
        if (! $paragraf) {
            throw ValidationException::withMessages(['butir' => 'AI tidak menghasilkan paragraf bersitasi dari acuan terkonfirmasi. Coba lagi.']);
        }

        return ['teks' => implode("\n\n", $paragraf), 'butir' => array_values(array_unique($dipakai))];
    }

    private function system(): string
    {
        return <<<'TXT'
Anda membantu koder menyusun DRAF surat sanggahan klaim BPJS. Draf akan diperiksa manusia sebelum dikirim.
Aturan wajib:
1. Gunakan HANYA butir acuan yang diberikan; jangan menambah pengetahuan, regulasi, atau fakta pasien dari luar.
2. Tiap paragraf wajib merujuk minimal satu "id" butir di "sitasi". Jangan merujuk id lain.
3. Jangan menulis kode ICD di teks dan jangan membahas besaran tarif.
4. Bahasa Indonesia formal, ringkas, maksimal 6 paragraf.
TXT;
    }

    /** Unduh draf (.txt atau .docx). */
    public function unduh(string $teks, string $format, string $nama): Response
    {
        $teks = str_replace("\r\n", "\n", $teks);
        if ($format === 'txt') {
            return response($teks, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"$nama.txt\""]);
        }

        return response($this->docx($teks), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => "attachment; filename=\"$nama.docx\""]);
    }

    /** .docx minimal (satu paragraf per baris) tanpa pustaka tambahan. */
    public function docx(string $teks): string
    {
        $p = collect(explode("\n", $teks))->map(fn ($l) => '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($l, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</w:t></w:r></w:p>')->implode('');
        $file = tempnam(sys_get_temp_dir(), 'draf');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' . $p . '</w:body></w:document>');
        $zip->close();
        $isi = (string) file_get_contents($file);
        @unlink($file);

        return $isi;
    }
}
