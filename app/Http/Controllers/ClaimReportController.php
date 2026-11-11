<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Support\Audit;
use Illuminate\Support\Facades\Gate;

class ClaimReportController extends Controller
{
    public function __invoke(Claim $claim)
    {
        Gate::authorize('view', $claim);
        $claim->load(['documents', 'approver', 'checklistUser']);
        $review = $claim->completedReview();
        Audit::log('laporan.cetak', $claim, 'Membuka laporan pemeriksaan untuk dicetak');

        return view('claims.report', [
            'claim' => $claim,
            'review' => $review,
            'stale' => $review ? $claim->reviewIsStale($review) : false,
        ]);
    }
}
