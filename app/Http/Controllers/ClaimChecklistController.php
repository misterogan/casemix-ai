<?php

namespace App\Http\Controllers;

use App\Models\Claim;
use App\Support\Audit;
use App\Support\Eklaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClaimChecklistController extends Controller
{
    public function update(Request $request, Claim $claim)
    {
        Gate::authorize('update', $claim);

        $checked = collect(Eklaim::CHECKLIST)->keys()
            ->mapWithKeys(fn ($k) => [$k => $request->boolean("items.$k")])->all();

        $claim->forceFill([
            'checklist' => $checked,
            'checklist_by' => $request->user()->id,
            'checklist_at' => now(),
        ])->save();

        $n = count(array_filter($checked));
        Audit::log('daftar_cek.simpan', $claim, "Menyimpan daftar cek ($n dari " . count($checked) . ' butir)', ['items' => $checked]);

        return back()->with('ok', "Daftar cek disimpan ($n dari " . count($checked) . ' butir).');
    }
}
