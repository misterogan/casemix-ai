<?php

namespace App\Http\Controllers;

use App\Models\AiUsage;
use App\Services\Ai\AiBudget;

/** Sistem → Pemakaian AI: token & biaya dari ai_usage. Tanpa data pasien (hanya jenis tugas & id rujukan). */
class AiUsageController extends Controller
{
    public function __invoke(AiBudget $budget)
    {
        $rows = AiUsage::where('created_at', '>=', now()->startOfMonth())->get();
        $agg = fn ($g) => (object) ['biaya' => $g->sum('biaya_usd'), 'jumlah' => $g->count(),
            'token' => $g->sum(fn ($r) => $r->input_tokens + $r->output_tokens + $r->cache_read_tokens)];
        $analisa = $rows->where('jenis', 'analisa')->whereNotNull('ref_id')->groupBy('ref_id')->map(fn ($g) => $agg($g)->token);

        return view('sistem.pemakaian-ai', [
            'total' => AiUsage::totalBulanIni(),
            'anggaran' => $budget->anggaran(),
            'persen' => $budget->persenPemakaian(),
            'perJenis' => $rows->groupBy('jenis')->map(fn ($g, $j) => (object) (['jenis' => $j] + (array) $agg($g)))->sortByDesc('biaya')->values(),
            'perModelHari' => $rows->groupBy(fn ($r) => $r->created_at->format('Y-m-d') . '|' . $r->model)
                ->map(function ($g, $k) use ($agg) {
                    [$hari, $model] = explode('|', $k, 2);

                    return (object) (['hari' => $hari, 'model' => $model] + (array) $agg($g));
                })->sortBy('hari')->values(),
            'rataAnalisa' => $analisa->isEmpty() ? null : (int) round($analisa->avg()),
            'terbesar' => $rows->sortByDesc('biaya_usd')->take(10)->values(),
        ]);
    }
}
