<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Ai\AiBudget;
use App\Support\Audit;
use Illuminate\Http\Request;

/** Anggaran AI bulanan (lapis kedua, di dalam aplikasi). */
class AiBudgetController extends Controller
{
    public function index(AiBudget $budget)
    {
        return view('admin.budget.index', [
            'status' => $budget->status(),
            'report' => $budget->report(),
            'average' => $budget->averageJobCost(),
        ]);
    }

    public function update(Request $request, AiBudget $budget)
    {
        $data = $request->validate([
            'budget' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'warn_pct' => ['required', 'integer', 'min:10', 'max:99'],
        ]);
        $old = ['budget' => $budget->budget(), 'warn_pct' => $budget->warnPct()];
        $new = ['budget' => $data['budget'] !== null && (float) $data['budget'] > 0 ? round((float) $data['budget'], 2) : null,
            'warn_pct' => (int) $data['warn_pct']];

        AppSetting::put(AiBudget::KEY_BUDGET, $new['budget'], $request->user()->id);
        AppSetting::put(AiBudget::KEY_WARN, $new['warn_pct'], $request->user()->id);
        Audit::log('admin.anggaran', null, 'Mengubah anggaran AI: '
            . ($new['budget'] ? 'US$' . number_format($new['budget'], 2) . '/bulan' : 'tanpa batas')
            . ", peringatan {$new['warn_pct']}%", ['perubahan' => [
                'anggaran_usd' => ['lama' => $old['budget'], 'baru' => $new['budget']],
                'peringatan_pct' => ['lama' => $old['warn_pct'], 'baru' => $new['warn_pct']],
            ]]);

        return back()->with('ok', 'Anggaran AI disimpan.');
    }
}
