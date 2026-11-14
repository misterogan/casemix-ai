<?php

namespace App\Http\Controllers;

use App\Enums\ClaimStatus;
use App\Models\Claim;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ClaimApprovalController extends Controller
{
    public function approve(Request $request, Claim $claim)
    {
        Gate::authorize('approve', $claim);

        return DB::transaction(function () use ($request, $claim) {
            $claim = Claim::lockForUpdate()->with('documents')->findOrFail($claim->id);
            if ($blockers = $claim->approvalBlockers($request->user())) {
                return back()->with('warn', 'Belum bisa disetujui: ' . implode(' ', $blockers));
            }

            $claim->forceFill([
                'status' => ClaimStatus::Disetujui,
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ])->save();

            $review = $claim->completedReview();
            Audit::log('berkas.setujui', $claim, 'Menyetujui berkas', [
                'review' => $review?->id,
                'kritis_terbuka' => $review?->countBy(\App\Enums\Severity::Kritis),
            ]);

            return back()->with('ok', 'Berkas disetujui dan dikunci. Lanjutkan entri dan Final Klaim di E-Klaim.');
        });
    }

    public function reopen(Request $request, Claim $claim)
    {
        Gate::authorize('reopen', $claim);
        $data = $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:500']],
            ['alasan.required' => 'Tulis alasan membuka kembali berkas.']);

        $claim->forceFill(['status' => ClaimStatus::BelumDiperiksa, 'approved_by' => null, 'approved_at' => null])->save();
        $claim->markContentChanged(); // memaksa pemeriksaan ulang dan daftar cek ulang
        $claim->refresh()->refreshStatus();
        Audit::log('berkas.buka_kembali', $claim, 'Membuka kembali berkas: ' . $data['alasan'], ['alasan' => $data['alasan']]);

        return back()->with('ok', 'Berkas dibuka kembali. Jalankan pemeriksaan ulang dan isi daftar cek sebelum disetujui lagi.');
    }
}
