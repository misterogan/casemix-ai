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

        $antrean = FtpFolder::query()->whereNull('missing_at')
            ->where(fn ($w) => $w->where('analysis_status', 'gagal')->orWhereNotNull('rubric_kurang'))
            ->get()
            ->map(fn ($f) => ['folder' => $f, 'vonis' => $verdict->verdict($f)])
            ->filter(fn ($r) => in_array($r['vonis'], ['tinjau', 'blokir'], true))
            ->map(fn ($r) => $r + ['prioritas' => $verdict->priority($r['folder']), 'tenggat' => $verdict->dueDate($r['folder'])])
            ->sort(fn ($a, $b) => [$b['prioritas'], $a['folder']->id] <=> [$a['prioritas'], $b['folder']->id])
            ->values();

        $today = now()->startOfDay();
        $summary = [
            'selesai' => FtpFolder::query()->whereNull('missing_at')->where('rubric_kurang', 0)
                ->where(fn ($w) => $w->where('analyzed_at', '>=', $today)
                    ->orWhereHas('rubricItems', fn ($r) => $r->where('accepted_at', '>=', $today)))
                ->get()->filter(fn ($f) => $verdict->verdict($f) === 'siap')->count(),
            'tersisa' => $antrean->count(),
            'terlambat' => $antrean->filter(fn ($r) => $r['tenggat'] && $r['tenggat']->copy()->startOfDay()->lt($today))->count(),
        ];

        $rows = $antrean
            ->filter(fn ($r) => ! $saya || $r['folder']->assigned_to === $request->user()->id)
            ->filter(fn ($r) => $vonis === '' || $r['vonis'] === $vonis)
            ->filter(fn ($r) => ! $from || ($r['folder']->files_modified_at ?? $r['folder']->first_seen_at)?->gte($from))
            ->values();

        $next = $rows->first();
        if ($next) {
            $next += self::identity($next['folder']);
            $next['kurang'] = $next['folder']->rubricItems()->where('status', 'kurang')->orderBy('id')->limit(5)->get()->map->label();
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $items = $rows->forPage($page, self::PER_PAGE)->values();
        $dx = CodeSuggestion::query()->join('folder_analyses', 'folder_analyses.id', '=', 'code_suggestions.folder_analysis_id')
            ->whereIn('folder_analyses.ftp_folder_id', $items->pluck('folder.id'))
            ->where('code_suggestions.peran', 'utama')->where('code_suggestions.system', 'icd10')
            ->orderByDesc('code_suggestions.folder_analysis_id')
            ->get(['code_suggestions.code', 'folder_analyses.ftp_folder_id'])
            ->unique('ftp_folder_id')->pluck('code', 'ftp_folder_id');
        $bpjs = \App\Models\VclaimKlaim::whereIn('no_sep', $items->pluck('folder.no_sep')->filter())->pluck('status_kode', 'no_sep');
        $items = $items->map(fn ($r) => $r + ['dx' => $dx[$r['folder']->id] ?? null, 'bpjs' => $bpjs[$r['folder']->no_sep] ?? null] + self::identity($r['folder']));

        return view('work-queue.index', [
            'rows' => new LengthAwarePaginator($items, $rows->count(), self::PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]),
            'petugas' => $petugas = User::role('koordinator')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'tampilPenugasan' => $petugas->count() > 1,
            'summary' => $summary,
            'next' => $next,
            'filters' => ['saya' => $saya, 'vonis' => $vonis, 'periode' => $period],
        ]);
    }

    /** Nama pasien dari nama folder (tanpa awalan RM dan garis bawah di ujung) + No. RM. */
    public static function identity(FtpFolder $f): array
    {
        $name = (string) $f->name;
        $rm = $f->no_rm;
        if ($rm && str_starts_with($name, $rm)) {
            $name = substr($name, strlen($rm));
        } elseif (preg_match('/^(\d{3,})[_\s-]+/', $name, $m)) {
            $rm ??= $m[1];
            $name = substr($name, strlen($m[0]));
        }
        $name = trim(str_replace('_', ' ', trim($name, "_- \t")));

        return ['pasien' => $name !== '' ? $name : ($f->nama_pasien ?: $f->name), 'rm' => $rm];
    }
}
