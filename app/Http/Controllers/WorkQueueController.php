<?php

namespace App\Http\Controllers;

use App\Models\CodeSuggestion;
use App\Models\FtpFolder;
use App\Models\User;
use App\Services\Dashboard\ClaimVerdict;
use App\Services\Dashboard\DashboardStats;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/** Antrean kerja Koordinator: folder vonis tinjau/blokir, urut prioritas (tanpa AI, tanpa tarif). */
class WorkQueueController extends Controller
{
    public const PER_PAGE = 50;

    public function __invoke(Request $request, ClaimVerdict $verdict)
    {
        $v = $request->validate([
            'saya' => ['nullable', 'boolean'],
            'vonis' => ['nullable', 'in:tinjau,blokir'],
            'periode' => ['nullable', 'string'],
        ]);
        $saya = (bool) ($v['saya'] ?? false);
        $vonis = $v['vonis'] ?? '';
        $period = DashboardStats::period($v['periode'] ?? 'semua');
        $from = match ($period) {
            '7' => now()->subDays(7)->startOfDay(),
            '30' => now()->subDays(30)->startOfDay(),
            'bulan' => now()->startOfMonth(),
            default => null,
        };

        $rows = FtpFolder::query()->whereNull('missing_at')
            ->where(fn ($w) => $w->where('analysis_status', 'gagal')->orWhereNotNull('rubric_kurang'))
            ->when($saya, fn ($w) => $w->where('assigned_to', $request->user()->id))
            ->when($from, fn ($q) => $q->where(fn ($w) => $w->where('files_modified_at', '>=', $from)
                ->orWhere(fn ($x) => $x->whereNull('files_modified_at')->where('first_seen_at', '>=', $from))))
            ->get()
            ->map(fn ($f) => ['folder' => $f, 'vonis' => $verdict->verdict($f)])
            ->filter(fn ($r) => $vonis !== '' ? $r['vonis'] === $vonis : in_array($r['vonis'], ['tinjau', 'blokir'], true))
            ->map(fn ($r) => $r + ['prioritas' => $verdict->priority($r['folder']), 'tenggat' => $verdict->dueDate($r['folder'])])
            ->sort(fn ($a, $b) => [$b['prioritas'], $a['folder']->id] <=> [$a['prioritas'], $b['folder']->id])
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $rows->forPage($page, self::PER_PAGE)->values();
        $dx = CodeSuggestion::query()->join('folder_analyses', 'folder_analyses.id', '=', 'code_suggestions.folder_analysis_id')
            ->whereIn('folder_analyses.ftp_folder_id', $items->pluck('folder.id'))
            ->where('code_suggestions.peran', 'utama')->where('code_suggestions.system', 'icd10')
            ->orderByDesc('code_suggestions.folder_analysis_id')
            ->get(['code_suggestions.code', 'folder_analyses.ftp_folder_id'])
            ->unique('ftp_folder_id')->pluck('code', 'ftp_folder_id');
        $items = $items->map(fn ($r) => $r + ['dx' => $dx[$r['folder']->id] ?? null]);

        return view('work-queue.index', [
            'rows' => new LengthAwarePaginator($items, $rows->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'petugas' => User::role('koordinator')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => ['saya' => $saya, 'vonis' => $vonis, 'periode' => $period],
        ]);
    }
}
