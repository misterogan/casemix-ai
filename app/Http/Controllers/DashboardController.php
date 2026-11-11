<?php

namespace App\Http\Controllers;

use App\Enums\ClaimStatus;
use App\Enums\ReviewStatus;
use App\Models\AiProvider;
use App\Models\AiReview;
use App\Models\Claim;

class DashboardController extends Controller
{
    public function __invoke()
    {
        $counts = Claim::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard.index', [
            'counts' => collect(ClaimStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'needsAttention' => Claim::query()
                ->whereIn('status', [ClaimStatus::PerluDilengkapi->value, ClaimStatus::SepBelumAda->value, ClaimStatus::BelumKoreksi->value])
                ->with('latestReview')->latest('updated_at')->limit(8)->get(),
            'readyToApprove' => Claim::query()->where('status', ClaimStatus::SudahKoreksi->value)
                ->with('latestReview')->latest('updated_at')->limit(8)->get(),
            'running' => AiReview::query()->whereIn('status', [ReviewStatus::Antre->value, ReviewStatus::Berjalan->value])->count(),
            'approvedToday' => Claim::query()->whereDate('approved_at', today())->count(),
            'provider' => AiProvider::default(),
        ]);
    }
}
