<x-app-layout title="Antrean kerja">
@php
    $edge = fn ($v) => $v === 'blokir' ? 'border-l-triage-red' : 'border-l-triage-amber';
    $sisa = fn ($t) => $t ? (int) now()->startOfDay()->diffInDays($t->copy()->startOfDay(), false) : null;
@endphp
<div class="space-y-4">
    <header>
        <h1 class="text-xl font-bold text-ink">Antrean kerja</h1>
        <p class="text-sm text-ink-muted" data-ringkasan>
            Hari ini <span class="tabular font-semibold text-ink" data-selesai>{{ $summary['selesai'] }}</span> selesai,
            <span class="tabular font-semibold text-ink" data-tersisa>{{ $summary['tersisa'] }}</span> tersisa,
            <span @class(['tabular font-semibold', 'text-triage-red' => $summary['terlambat'] > 0, 'text-ink' => $summary['terlambat'] === 0]) data-terlambat>{{ $summary['terlambat'] }}</span> terlambat
        </p>
    </header>

    @if ($next)
        @php $nf = $next['folder']; $ns = $sisa($next['tenggat']); @endphp
        <x-panel class="border-l-4 {{ $edge($next['vonis']) }} p-5" data-next="{{ $nf->id }}">
            <div class="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
                <div class="min-w-0">
                    <p class="text-xs font-medium text-ink-muted">Kerjakan berikutnya</p>
                    <p class="mt-1 truncate text-title font-bold text-ink">{{ $next['pasien'] }}</p>
                    <p class="tabular mt-1 text-sm text-ink-muted">RM {{ $next['rm'] ?: '—' }} · {{ $nf->no_sep ? 'SEP ' . $nf->no_sep : 'SEP belum ada' }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                        <x-verdict :status="$next['vonis']"/>
                        @if ($next['tenggat'])
                            <span class="tabular text-ink-muted">Tenggat {{ $next['tenggat']->format('d/m/Y') }} ·
                                @if ($ns < 0)<span class="font-semibold text-triage-red">terlambat {{ -$ns }} hari</span>@else sisa {{ $ns }} hari @endif
                            </span>
                        @endif
                    </div>
                </div>
                <div class="md:w-80 md:shrink-0">
                    @if ($next['kurang']->isNotEmpty())
                        <p class="text-xs font-medium text-ink-muted">Butir kurang</p>
                        <ul class="mt-1.5 space-y-1 text-sm text-ink">
                            @foreach ($next['kurang'] as $label)
                                <li class="flex gap-2"><span class="mt-2 size-1.5 shrink-0 rounded-full bg-triage-red" aria-hidden="true"></span>{{ $label }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <a href="{{ route('folders.show', $nf) }}" class="btn-primary mt-4">Buka folder</a>
                </div>
            </div>
        </x-panel>
    @endif

    <form method="GET" action="{{ route('work-queue.index') }}" class="flex flex-wrap items-end gap-3">
        <div>
            <label for="vonis" class="block text-xs text-ink-muted">Vonis</label>
            <select id="vonis" name="vonis" class="field rounded-control">
                <option value="">Tinjau &amp; Blokir</option>
                <option value="tinjau" @selected($filters['vonis'] === 'tinjau')>Tinjau</option>
                <option value="blokir" @selected($filters['vonis'] === 'blokir')>Blokir</option>
            </select>
        </div>
        <div>
            <label for="periode" class="block text-xs text-ink-muted">Periode</label>
            <select id="periode" name="periode" class="field rounded-control">
                @foreach (\App\Services\Dashboard\DashboardStats::PERIODS as $k => $l)
                    <option value="{{ $k }}" @selected($filters['periode'] === (string) $k)>{{ $l }}</option>
                @endforeach
            </select>
        </div>
        @if ($tampilPenugasan)
            <label class="flex h-[38px] items-center gap-2 text-sm text-ink"><input type="checkbox" name="saya" value="1" @checked($filters['saya'])> Tugas saya saja</label>
        @endif
        <button class="btn-primary">Terapkan</button>
    </form>

    @if ($rows->isEmpty())
        <x-panel>
            <x-empty-state title="Semua folder siap diajukan."
                text="Folder baru dari FTP akan muncul di sini setelah dianalisa."
                action="Buka verifikasi berkas" :href="route('folders.index')"/>
        </x-panel>
    @else
        <x-panel class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-ink-muted">
                    <tr class="border-b border-line">
                        <th class="px-4 py-2.5 font-medium">Prioritas</th>
                        <th class="px-4 py-2.5 font-medium">Pasien</th>
                        <th class="px-4 py-2.5 font-medium">Dx</th>
                        <th class="px-4 py-2.5 font-medium">Vonis</th>
                        <th class="px-4 py-2.5 font-medium">Tenggat</th>
                        <th class="px-4 py-2.5 font-medium">Butir kurang</th>
                        @if ($tampilPenugasan)<th class="px-4 py-2.5 font-medium" data-kolom-penugasan>Ditugaskan ke</th>@endif
                    </tr>
                </thead>
                <tbody>
                @foreach ($rows as $r)
                    @php $f = $r['folder']; $s = $sisa($r['tenggat']); @endphp
                    <tr class="relative border-t border-line transition-colors hover:bg-paper" data-folder="{{ $f->id }}">
                        <td class="border-l-4 {{ $edge($r['vonis']) }} px-4 py-3" data-prioritas="{{ $r['prioritas'] }}"><x-priority-meter :value="$r['prioritas']"/></td>
                        <td class="px-4 py-3">
                            <a href="{{ route('folders.show', $f) }}" class="font-semibold text-ink after:absolute after:inset-0 after:content-['']">{{ $r['pasien'] }}</a>
                            <span class="tabular block text-xs text-ink-muted">RM {{ $r['rm'] ?: '—' }} · {{ $f->no_sep ? 'SEP ' . $f->no_sep : 'SEP belum ada' }}</span>
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $r['dx'] ?? '—' }}</td>
                        <td class="px-4 py-3"><x-verdict :status="$r['vonis']"/></td>
                        <td class="tabular px-4 py-3 whitespace-nowrap">
                            @if ($r['tenggat'])
                                {{ $r['tenggat']->format('d/m/Y') }}
                                @if ($s < 0)<span class="block text-xs font-semibold text-triage-red">terlambat {{ -$s }} hari</span>@else<span class="block text-xs text-ink-muted">sisa {{ $s }} hari</span>@endif
                            @else — @endif
                        </td>
                        <td class="tabular px-4 py-3">{{ (int) $f->rubric_kurang }}</td>
                        @if ($tampilPenugasan)
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('folders.assign', $f) }}" class="relative z-10">
                                    @csrf @method('PUT')
                                    <select name="user_id" class="field rounded-control" aria-label="Tugaskan folder {{ $r['pasien'] }}" onchange="this.form.submit()">
                                        <option value="">Belum ditugaskan</option>
                                        @foreach ($petugas as $u)
                                            <option value="{{ $u->id }}" @selected($f->assigned_to === $u->id)>{{ $u->name }}</option>
                                        @endforeach
                                    </select>
                                    <noscript><button class="btn-ghost">Simpan</button></noscript>
                                </form>
                            </td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </x-panel>
        {{ $rows->links() }}
    @endif
</div>
</x-app-layout>
