<?php

namespace App\Services\Import;

use App\Enums\ClaimStatus;
use App\Jobs\RunClaimReview;
use App\Models\AiReview;
use App\Models\Claim;
use App\Models\ImportBatch;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Menyimpan hasil impor yang sudah dikoreksi petugas menjadi berkas klaim, lalu mengantre pemeriksaan AI. */
class ImportCommitter
{
    /**
     * @param  array<int, array{type: string, group_key: string, skip?: bool}>  $corrections  per item id
     * @param  array<string, array>  $groups  data identitas per group_key yang diisi petugas
     * @return array<int> id klaim yang dibuat/diperbarui
     */
    public function commit(ImportBatch $batch, array $corrections, array $groups, int $userId, bool $runReview): array
    {
        $claimIds = DB::transaction(function () use ($batch, $corrections, $groups, $userId) {
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
            foreach ($byGroup as $key => $items) {
                $g = $groups[$key] ?? [];
                $claim = $this->findOrCreateClaim($g, $items, $userId);

                foreach ($items as $item) {
                    $dir = "claims/{$claim->id}";
                    $newPath = $dir . '/' . basename($item->local_path);
                    Storage::disk(config('casemix.storage_disk'))->move($item->local_path, $newPath);

                    $claim->documents()->create([
                        'type' => $item->detected_type,
                        'original_name' => $item->original_name,
                        'path' => $newPath,
                        'mime' => $item->mime,
                        'size' => Storage::disk(config('casemix.storage_disk'))->size($newPath),
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

            $batch->update(['status' => 'disimpan', 'committed_at' => now()]);

            return array_values(array_unique($ids));
        });

        if ($runReview) {
            foreach ($claimIds as $id) {
                $review = AiReview::create(['claim_id' => $id, 'status' => 'antre', 'requested_by' => $userId]);
                RunClaimReview::dispatch($review->id);
            }
        }

        return $claimIds;
    }

    private function findOrCreateClaim(array $g, array $items, int $userId): Claim
    {
        $detected = collect($items)->pluck('detected');
        $pick = fn (string $k) => $g[$k] ?? $detected->pluck($k)->filter()->countBy()->sortDesc()->keys()->first();

        $sep = $pick('no_sep');
        $rm = $pick('no_rm');

        $existing = Claim::query()
            ->where('status', '!=', ClaimStatus::Disetujui->value)
            ->when($sep, fn ($q) => $q->where('no_sep', $sep), fn ($q) => $q->where('no_rm', $rm ?? '__none__'))
            ->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        $tanggal = $pick('tanggal');

        return Claim::create([
            'nama_pasien' => $pick('nama') ?? 'Belum dikenali',
            'no_rm' => $rm ?? '-',
            'no_sep' => $sep,
            'jenis_rawat' => $g['jenis_rawat'] ?? 'inap',
            'tanggal_masuk' => $tanggal && strtotime($tanggal) ? $tanggal : now()->toDateString(),
            'dpjp' => $g['dpjp'] ?? '-',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }
}
