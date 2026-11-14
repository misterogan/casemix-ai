<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\RunFieldExtraction;
use App\Models\AiProvider;
use App\Models\Claim;
use App\Models\ClaimExtraction;
use App\Services\Ai\AiBudget;
use App\Services\Ai\FieldExtractor;
use App\Support\Audit;
use App\Support\ClaimFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Usulan isian data klaim dari dokumen: jalankan, pantau, dan terapkan yang dipilih petugas. */
class ClaimExtractionController extends Controller
{
    public function store(Request $request, Claim $claim)
    {
        Gate::authorize('update', $claim);

        if ($warn = self::startBlocker($claim)) {
            return back()->with('warn', $warn);
        }

        self::start($claim, $request->user()->id);
        Audit::log('isian.jalankan', $claim, 'Meminta usulan isian dari dokumen');

        return back()->with('ok', 'Dokumen sedang dibaca. Usulan isian muncul di halaman ini saat selesai.');
    }

    public function status(Claim $claim)
    {
        Gate::authorize('view', $claim);
        $x = $claim->extractions()->first();

        return response()->json(['status' => $x?->status?->value, 'active' => (bool) $x?->status?->isActive()]);
    }

    /** Terapkan HANYA field yang dicentang petugas, dengan jejak audit nilai lama dan baru. */
    public function apply(Request $request, Claim $claim, ClaimExtraction $extraction)
    {
        Gate::authorize('update', $claim);
        abort_unless($extraction->claim_id === $claim->id && $extraction->status === ReviewStatus::Selesai, 404);

        $data = $request->validate(['fields' => ['required', 'array', 'min:1'], 'fields.*' => ['string']],
            ['fields.required' => 'Pilih minimal satu isian untuk diterapkan.']);

        $suggestions = $extraction->suggestions ?? [];
        $skipped = [];
        $applied = [];

        DB::transaction(function () use ($data, $suggestions, $claim, $request, $extraction, &$skipped, &$applied) {
            foreach (array_unique($data['fields']) as $field) {
                if (! isset($suggestions[$field])) {
                    continue;
                }
                $value = $suggestions[$field]['nilai'];
                if ($field === 'no_sep' && Claim::where('no_sep', $value)->whereKeyNot($claim->id)->exists()) {
                    $skipped[] = "No. SEP $value sudah dipakai berkas lain";

                    continue;
                }
                ClaimFields::apply($claim, $field, $value);
                $applied[] = $field;
            }

            $diff = $claim->contentDiff();
            if ($diff === []) {
                return;
            }
            $claim->updated_by = $request->user()->id;
            $claim->save();
            $claim->markContentChanged();

            $extraction->update([
                'applied_by' => $request->user()->id,
                'applied_at' => now(),
                'applied_fields' => array_values(array_unique(array_merge($extraction->applied_fields ?? [], $applied))),
            ]);
            Audit::log('berkas.isi_otomatis', $claim, 'Menerapkan usulan isian dari dokumen: ' . implode(', ', array_keys($diff)), [
                'field' => array_keys($diff),
                'perubahan' => $diff,
                'ekstraksi' => $extraction->id,
                'model' => $extraction->model,
            ]);
        });

        $claim->refresh()->refreshStatus();
        $msg = count($applied) . ' isian diterapkan. Periksa dengan AI setelah data lengkap.';

        return back()->with($skipped ? 'warn' : 'ok', $skipped ? $msg . ' Dilewati: ' . implode('; ', $skipped) . '.' : $msg);
    }

    /** Alasan usulan isian belum bisa dijalankan, atau null bila bisa. */
    public static function startBlocker(Claim $claim): ?string
    {
        if (! AiProvider::default()) {
            return 'Belum ada penyedia AI yang aktif. Minta Admin mengatur Penyedia AI.';
        }
        if ($blocked = app(AiBudget::class)->startBlocker()) {
            return $blocked;
        }
        if (FieldExtractor::sourceDocuments($claim->loadMissing('documents'))->isEmpty()) {
            return 'Unggah SEP, resume medis, atau rincian biaya terlebih dahulu.';
        }
        if ($claim->extractions()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()) {
            return 'Dokumen berkas ini sedang dibaca.';
        }

        return null;
    }

    public static function start(Claim $claim, int $userId): ClaimExtraction
    {
        $x = ClaimExtraction::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre, 'requested_by' => $userId]);
        RunFieldExtraction::dispatch($x->id);

        return $x;
    }
}
