{{-- Peringatan anggaran AI. Habis: semua yang bisa menjalankan AI. Mendekati batas: Admin dan Koordinator. --}}
@php
    $user = auth()->user();
    $s = $user ? \Illuminate\Support\Facades\Cache::remember('ai_budget_status', 60, fn () => app(\App\Services\Ai\AiBudget::class)->status()) : null;
    $manager = $user && ($user->can('admin.manage') || $user->can('claims.approve'));
    $show = $s && (($s['state'] === 'habis' && ($manager || $user->can('folders.analyze') || $user->can('claims.review'))) || ($s['state'] === 'peringatan' && $manager));
@endphp
@if ($show)
    <div @class(['mb-5 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-4 py-3 text-sm',
                 'border-kritis/25 bg-kritis-muda text-kritis' => $s['state'] === 'habis',
                 'border-waspada/25 bg-waspada-muda text-waspada' => $s['state'] === 'peringatan']) role="status">
        <span class="flex-1">
            @if ($s['state'] === 'habis')
                <strong>Anggaran AI bulan ini habis.</strong> Pemeriksaan AI dan isi otomatis ditahan sampai awal bulan depan; aturan otomatis tetap berjalan.
            @else
                <strong>Pemakaian AI {{ $s['pct'] }}% dari anggaran.</strong>
                Perkiraan US${{ number_format($s['spent'], 2) }} dari US${{ number_format($s['budget'], 2) }} bulan ini.
            @endif
        </span>
        @can('admin.manage')<a href="{{ route('admin.budget.index') }}" class="font-medium underline">Lihat anggaran</a>@endcan
    </div>
@endif
