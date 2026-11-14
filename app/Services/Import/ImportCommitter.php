<?php

namespace App\Services\Import;

use App\Http\Controllers\ClaimExtractionController;
use App\Jobs\RunClaimReview;
use App\Models\AiReview;
use App\Models\Claim;
use App\Models\ImportBatch;
use App\Services\Ai\AiBudget;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Menyimpan hasil impor yang sudah dikoreksi petugas menjadi berkas klaim, lalu mengantre pemeriksaan AI. */
class ImportCommitter
{
    public function __construct(private AiBudget $budget) {}

    /**
     * @param  array<int, array{type: string, group_key: string, skip?: bool}>  $corrections  per item id
     * @param  array<string, array>  $groups  data identitas per group_key yang diisi petugas
     * @return array{claims: array<int>, blocked: array<int, array{no_sep: string, claim_id: int, files: int}>}
     *         claims  = id berkas yang dibuat/diperbarui
     *         blocked = kelompok yang SEP-nya milik berkas yang sudah disetujui. File-nya tidak disimpan
     *                   dan tetap menunggu di impor ini sampai Koordinator membuka kembali berkas tersebut.
     */
    /** Langkah lanjutan setelah berkas disimpan. */
    public const NEXT_FILL = 'isi';      // siapkan usulan isian dari dokumen (disarankan)

    public const NEXT_REVIEW = 'periksa'; // langsung periksa dengan AI

    public const NEXT_NONE = 'tidak';

    public function commit(ImportBatch $batch, array $corrections, array $groups, int $userId, string $next = self::NEXT_NONE): array
    {
        $result = DB::transaction(function () use ($batch, $corrections, $groups, $userId) {
            $byGroup = [];
            foreach ($batch->items()->where('status', 'dipilah')->get() as $item) {
                $c = $corrections[$item->id] ?? [];
                if (! empty($c['skip'])) {
                    $item->update(['status' => 'dilewati']);

                    continue;
                }
                $item->detected_type = $c['type'] ?? $item->detected_type;
                $item->group_key = $c['group_key'] ?? $item->group_key;
                $item->save();
                $byGroup[$item->group_key][] = $item;
            }

            $ids = [];
            $blocked = [];
            $disk = Storage::disk(config('casemix.storage_disk'));

            foreach ($byGroup as $key => $items) {
                $g = $groups[$key] ?? [];
                $claim = $this->findOrCreateClaim($g, $items, $userId);

                if ($claim->isLocked()) {
                    // Jangan menempel dokumen ke berkas yang sudah disetujui, dan jangan membuat berkas kedua dengan SEP sama.
                    $blocked[] = ['no_sep' => $claim->no_sep, 'claim_id' => $claim->id, 'files' => count($items)];
                    Audit::system('impor.tertahan', $claim, count($items) . ' dokumen susulan dari FTP tertahan karena berkas sudah disetujui',
                        $userId, ['batch' => $batch->id]);

                    continue;
                }

                foreach ($items as $item) {
                    $newPath = "claims/{$claim->id}/" . basename($item->local_path);
                    $disk->move($item->local_path, $newPath);

                    $claim->documents()->create([
                        'type' => $item->detected_type,
                        'original_name' => $item->original_name,
                        'path' => $newPath,
                        'mime' => $item->mime,
                        'size' => $disk->size($newPath),
                        'sha256' => $item->sha256,
                        'source' => 'ftp',
                        'remote_path' => $item->remote_path,
                        'uploaded_by' => $userId,
                    ]);
                    $item->update(['status' => 'disimpan', 'claim_id' => $claim->id, 'local_path' => $newPath]);
                }

                $claim->markContentChanged();
                $claim->refresh()->refreshStatus();
                Audit::system('dokumen.impor', $claim, count($items) . ' dokumen diimpor dari FTP SIMRS', $userId, ['batch' => $batch->id]);
                $ids[] = $claim->id;
            }

            // Bila ada kelompok tertahan, impor tetap bisa disimpan lagi setelah berkasnya dibuka kembali.
            $batch->update($blocked === []
                ? ['status' => 'disimpan', 'committed_at' => now()]
                : ['status' => 'siap_ditinjau']);

            return ['claims' => array_values(array_unique($ids)), 'blocked' => $blocked];
        });

        // Usulan isian dulu: pemeriksaan AI baru berguna setelah petugas mengonfirmasi data klaim,
        // jadi keduanya tidak dijalankan bersamaan (menghemat token).
        // Anggaran dicek per berkas karena setiap job yang diantre ikut diperhitungkan.
        $result['ai_skipped'] = 0;
        $result['ai_skipped_reason'] = null;
        foreach ($result['claims'] as $id) {
            $claim = Claim::find($id);
            if ($next === self::NEXT_NONE) {
                continue;
            }
            if ($blocked = $this->budget->startBlocker()) {
                $result['ai_skipped']++;
                $result['ai_skipped_reason'] = $blocked;

                continue;
            }
            if ($next === self::NEXT_FILL && ClaimExtractionController::startBlocker($claim) === null) {
                ClaimExtractionController::start($claim, $userId);
            } elseif ($next === self::NEXT_REVIEW) {
                $review = AiReview::create(['claim_id' => $id, 'status' => 'antre', 'requested_by' => $userId]);
                RunClaimReview::dispatch($review->id);
            }
        }

        return $result;
    }

    /**
     * Cari berkas tujuan:
     * - Ada SEP  → berkas dengan SEP itu, apa pun statusnya (SEP unik). Bila sudah disetujui, pemanggil menahan file.
     * - Tanpa SEP → hanya berkas terbuka dengan No. RM sama yang JUGA belum punya SEP. Berkas dengan SEP lain
     *               adalah episode rawat lain dan tidak boleh digabung.
     * Field yang tidak diketahui dibiarkan kosong (bukan ditebak) agar ditangkap aturan dan menahan persetujuan.
     */
    private function findOrCreateClaim(array $g, array $items, int $userId): Claim
    {
        $detected = collect($items)->pluck('detected');
        $pick = fn (string $k) => $g[$k] ?? $detected->pluck($k)->filter()->countBy()->sortDesc()->keys()->first();

        $sep = $pick('no_sep') ? strtoupper(preg_replace('/\s+/', '', $pick('no_sep'))) : null;
        $rm = $pick('no_rm');

        $existing = $sep
            ? Claim::query()->where('no_sep', $sep)->first()
            : ($rm ? Claim::query()
                ->where('no_rm', $rm)
                ->whereNull('no_sep')
                ->where('status', '!=', \App\Enums\ClaimStatus::Disetujui->value)
                ->latest('id')->first() : null);

        if ($existing) {
            return $existing;
        }

        $tanggal = $pick('tanggal_masuk') ?? $pick('tanggal');
        $jenisRawat = $pick('jenis_rawat');

        return Claim::create([
            'nama_pasien' => $pick('nama'),
            'no_rm' => $rm ?? '-',
            'no_sep' => $sep,
            'jenis_rawat' => in_array($jenisRawat, ['inap', 'jalan'], true) ? $jenisRawat : null,
            'tanggal_masuk' => $tanggal && strtotime($tanggal) ? date('Y-m-d', strtotime($tanggal)) : null,
            'dpjp' => $pick('dpjp'),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
