<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Jobs\RunClaimReview;
use App\Models\AiProvider;
use App\Models\AiReview;
use App\Models\Claim;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClaimReviewController extends Controller
{
    public function store(Request $request, Claim $claim)
    {
        Gate::authorize('review', $claim);

        if (! AiProvider::default()) {
            return back()->with('warn', 'Belum ada penyedia AI yang aktif. Minta Admin mengatur Penyedia AI.');
        }
        if ($claim->documents()->count() === 0) {
            return back()->with('warn', 'Unggah dokumen terlebih dahulu sebelum diperiksa.');
        }
        if ($claim->reviews()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->exists()) {
            return back()->with('warn', 'Pemeriksaan untuk berkas ini sedang berjalan.');
        }

        $review = AiReview::create(['claim_id' => $claim->id, 'status' => ReviewStatus::Antre, 'requested_by' => $request->user()->id]);
        RunClaimReview::dispatch($review->id);
        Audit::log('ai.jalankan', $claim, 'Menjalankan pemeriksaan AI');

        return back()->with('ok', 'Pemeriksaan dimulai. Halaman akan diperbarui otomatis saat selesai.');
    }

    public function status(Claim $claim)
    {
        Gate::authorize('view', $claim);
        $r = $claim->reviews()->first();

        return response()->json([
            'status' => $r?->status?->value,
            'active' => (bool) $r?->status?->isActive(),
        ]);
    }
}
