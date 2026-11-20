<?php

namespace App\Jobs;

use App\Enums\ReviewStatus;
use App\Models\FolderDocument;
use App\Models\FolderAnalysis;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AnggaranHabis;
use App\Services\Ai\AiManager;
use App\Services\Ai\AiResponse;
use App\Services\Import\SimrsFtp;
use App\Services\Verification\FolderAnalyzer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Tahap 1: AI membaca satu file. Error ditangkap di sini (status gagal) supaya dokumen lain dan periksa silang tetap jalan. */
class ReadFolderDocument implements ShouldQueue
{
    use Queueable;

    /** SEP dan dokumen tambahan: jawabannya pendek, cukup batas token kecil. */
    private const LIGHT_TYPES = ['sep', 'tambahan'];

    private const LIGHT_MAX_TOKENS = 4000;

    /** Batas dokumen lain dan tahap 2 (selalu min dengan nilai provider). */
    public const MAX_TOKENS = 6000;

    private const RETRY_MAX_TOKENS = 12000;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $documentId)
    {
        $this->onQueue(config('casemix.ai.queue'));
    }

    public function handle(AiManager $ai, FolderAnalyzer $analyzer, AiBudget $budget): void
    {
        $d = FolderDocument::with('folder')->find($this->documentId);
        if (! $d || $d->status !== ReviewStatus::Antre) {
            return;
        }
        // Berhenti saat gagal: dokumen sebelumnya gagal → yang ini tidak diproses (tanpa memanggil AI)
        if (FolderDocument::where('folder_analysis_id', $d->folder_analysis_id)->where('status', ReviewStatus::Gagal->value)->exists()) {
            $d->update(['status' => ReviewStatus::Dibatalkan, 'error' => 'Tidak diproses karena dokumen sebelumnya gagal.', 'finished_at' => now()]);

            return;
        }
        $d->update(['status' => ReviewStatus::Berjalan, 'started_at' => now(), 'error' => null]);

        try {
            if ($blocked = $budget->runBlocker('analisa')) {
                throw new RuntimeException($blocked);
            }
            $config = $ai->defaultConfig();
            $req = $analyzer->documentRequest($d, $config); // sekaligus menyimpan salinan arsip dari unduhan yang sama
            if (! $req) {
                $d->update(['status' => ReviewStatus::Selesai, 'summary' => null, 'findings' => [], 'finished_at' => now(),
                    'error' => 'Bukan PDF/gambar; tidak dibaca AI.']);

                return;
            }

            $req->refId = $d->folder_analysis_id;
            $req->maxTokens = min((int) $config->max_tokens, in_array($d->doc_type, self::LIGHT_TYPES, true) ? self::LIGHT_MAX_TOKENS : self::MAX_TOKENS);

            $res = $ai->for($config)->generate($req);
            $budget->charge($d, $res, $config->id); // biaya tercatat walau jawabannya gagal diolah

            // Terpotong: coba ulang SEKALI dengan batas dua kali lipat dan jawaban lebih singkat
            if (self::truncated($res)) {
                if ($blocked = $budget->runBlocker('analisa')) {
                    throw new RuntimeException($blocked);
                }
                $req->maxTokens = min((int) $config->max_tokens, $req->maxTokens * 2, self::RETRY_MAX_TOKENS);
                $req->text('Jawab lebih singkat: maks 3 temuan, tiap teks maks 8 kata.');
                $res = $ai->for($config)->generate($req);
                $budget->charge($d, $res, $config->id); // token kedua percobaan dijumlahkan
            }
            self::ensureComplete($res, $req->maxTokens);
            $r = $analyzer->parseDocument($d, $res->json());

            $d->update(['status' => ReviewStatus::Selesai, 'summary' => $r['summary'], 'findings' => $r['findings'], 'finished_at' => now()]);
        } catch (AnggaranHabis $e) { // ditahan, bukan gagal: "Lanjutkan" memproses lagi setelah anggaran dinaikkan
            $d->update(['status' => ReviewStatus::Dibatalkan, 'error' => $e->getMessage(), 'finished_at' => now()]);
            FolderAnalysis::whereKey($d->folder_analysis_id)->update(['status' => ReviewStatus::MenungguAnggaran->value, 'error' => $e->getMessage()]);
            $d->folder?->update(['analysis_status' => ReviewStatus::MenungguAnggaran->value]);
        } catch (Throwable $e) {
            $d->update(['status' => ReviewStatus::Gagal, 'error' => mb_substr($e->getMessage(), 0, 1000), 'finished_at' => now()]);
            report($e);
        }
    }

    /** Jawaban yang terpotong karena batas token dianggap gagal dengan pesan jelas, walau sisanya kebetulan bisa dibaca. */
    public static function ensureComplete(AiResponse $res, int $maxTokens): void
    {
        if (self::truncated($res)) {
            throw new RuntimeException(sprintf('Jawaban AI terpotong karena batas %d token tercapai (blok: %s; output_tokens: %s; panjang teks jawaban: %d karakter).',
                $maxTokens, implode(', ', $res->raw['block_types'] ?? []) ?: '-', $res->tokensOut ?? '-', $res->raw['text_length'] ?? mb_strlen($res->text)));
        }
    }

    private static function truncated(AiResponse $res): bool
    {
        return in_array($res->raw['stop_reason'] ?? null, ['max_tokens', 'length'], true);
    }

    // ------------------------------------------------------------ arsip salinan terenkripsi

    public static function archiveEnabled(): bool
    {
        return (bool) config('casemix.archive.enabled', true);
    }

    /** Dokumen lain dengan file yang sama (folder, nama, sidik) yang sudah punya salinan. */
    public static function storedSibling(FolderDocument $d): ?FolderDocument
    {
        return FolderDocument::where('ftp_folder_id', $d->ftp_folder_id)->where('name', $d->name)->where('file_fingerprint', $d->file_fingerprint)
            ->whereNotNull('stored_path')->latest('id')->first();
    }

    /** Simpan salinan dari FTP bila belum ada. Mengembalikan ukuran byte yang baru disimpan (0 bila memakai salinan yang ada / tidak disimpan). */
    public static function archiveFromFtp(FolderDocument $d, SimrsFtp $ftp): int
    {
        if (! self::archiveEnabled() || $d->stored_path) {
            return 0;
        }
        if ($s = self::storedSibling($d)) {
            $d->update($s->only(['stored_path', 'stored_sha256', 'stored_size', 'stored_at']));

            return 0;
        }
        $f = collect($d->folder?->files)->firstWhere('name', $d->name);
        if (! $f || FolderDocument::fingerprintOf($f) !== $d->file_fingerprint) {
            return 0; // file di FTP sudah lain dari yang dibaca
        }
        $disk = Storage::disk(config('casemix.storage_disk'));
        $tmp = 'tmp/arsip/' . Str::ulid();
        try {
            $local = $ftp->download($f['path'], $tmp . '/dokumen');

            return self::archiveContents($d, $disk->get($local));
        } finally {
            $disk->deleteDirectory($tmp);
        }
    }

    /** Enkripsi dan simpan isi file di arsip/{folder}/{sha256}.bin; sha256 sama dipakai ulang. Dokumen kembar ikut mewarisi. */
    public static function archiveContents(FolderDocument $d, string $contents): int
    {
        $sha = hash('sha256', $contents);
        $path = 'arsip/' . $d->ftp_folder_id . '/' . $sha . '.bin';
        $disk = Storage::disk(config('casemix.storage_disk'));
        $new = ! $disk->exists($path);
        if ($new && ! $disk->put($path, Crypt::encrypt($contents))) {
            throw new RuntimeException("Gagal menyimpan salinan arsip: $path");
        }
        $attrs = ['stored_path' => $path, 'stored_sha256' => $sha, 'stored_size' => strlen($contents), 'stored_at' => now()];
        FolderDocument::where('ftp_folder_id', $d->ftp_folder_id)->where('name', $d->name)->where('file_fingerprint', $d->file_fingerprint)
            ->whereNull('stored_path')->update($attrs);
        $d->forceFill($attrs)->save();

        return $new ? strlen($contents) : 0;
    }

    /** Isi asli dari salinan, atau null bila tidak ada. */
    public static function archivedContents(?FolderDocument $d): ?string
    {
        $disk = Storage::disk(config('casemix.storage_disk'));

        return $d?->stored_path && $disk->exists($d->stored_path) ? Crypt::decrypt($disk->get($d->stored_path)) : null;
    }
}
