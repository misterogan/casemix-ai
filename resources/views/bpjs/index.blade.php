<x-app-layout title="Klaim BPJS">
@php
    $rp = fn ($n) => $n === null ? '—' : number_format((float) $n, 0, ',', '.');
    $base = array_filter($filters);
@endphp
<div class="space-y-4">
    <header>
        <h1 class="text-xl font-bold text-ink">Klaim BPJS</h1>
        <p class="text-sm text-ink-muted">Data hanya dibaca dari hasil tarik VClaim. Halaman ini tidak memanggil BPJS.</p>
    </header>

    <nav class="flex gap-1 border-b border-garis-g text-sm" aria-label="Tab Klaim BPJS">
        @foreach (\App\Http\Controllers\BpjsController::TABS as $k => $l)
            <a href="{{ route('bpjs.index', ['tab' => $k] + ($k === 'ringkasan' ? [] : $base)) }}" data-tab="{{ $k }}"
               @if ($tab === $k) aria-current="page" @endif
               class="-mb-px border-b-2 px-3 py-2 {{ $tab === $k ? 'border-biru-aktif font-medium text-biru-aktif' : 'border-transparent text-abu hover:text-arang' }}">{{ $l }}</a>
        @endforeach
    </nav>

    @if (! $enabled)
        <x-panel><x-empty-state title="Bridging VClaim belum diaktifkan." data-vclaim-off/></x-panel>
    @else
        @if ($tab !== 'ringkasan')<p class="text-xs text-ink-muted" data-last-sync>Data BPJS terakhir ditarik {{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d/m/Y H:i') : 'belum pernah' }}</p>@endif

        @if ($tab === 'ringkasan')
            @include('bpjs.partials.ringkasan')
        @else
            @if ($tab === 'klaim' && ($rentangData->mx ?? null))
                <p class="text-xs text-ink-muted">Data tersedia {{ \Illuminate\Support\Carbon::parse($rentangData->mn)->format('d/m/Y') }} s.d. {{ \Illuminate\Support\Carbon::parse($rentangData->mx)->format('d/m/Y') }}</p>
            @endif
            <form method="GET" action="{{ route('bpjs.index') }}" class="flex flex-wrap items-end gap-2 text-sm">
                <input type="hidden" name="tab" value="{{ $tab }}">
                @foreach (['belum', 'kurang', 'lama', 'inacbg', 'sev', 'dx'] as $h)
                    @if ($filters[$h])<input type="hidden" name="{{ $h }}" value="{{ $filters[$h] }}">@endif
                @endforeach
                @if ($filters['inacbg'] || $filters['sev'] || $filters['dx'])
                    <p class="w-full text-xs text-ink-muted" data-filter-analisis>Filter dari Ringkasan:
                        {{ collect(['INA-CBG ' . $filters['inacbg'] => $filters['inacbg'], 'Severity ' . $filters['sev'] => $filters['sev'], 'Diagnosis ' . $filters['dx'] => $filters['dx']])->filter()->keys()->implode(', ') }}
                        · <a href="{{ route('bpjs.index', ['tab' => $tab]) }}" class="text-biru-aktif underline">hapus</a></p>
                @endif
                <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">{{ $tab === 'klaim' ? 'Tgl pulang dari' : 'Tgl SEP dari' }}</span>
                    <input type="date" name="dari" value="{{ $filters['dari'] }}" class="field num"></label>
                <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">sampai</span>
                    <input type="date" name="sampai" value="{{ $filters['sampai'] }}" class="field num"></label>
                <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Jenis</span>
                    <select name="jenis" class="field">
                        @foreach (['' => 'Semua', 'inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'] as $v => $l)
                            <option value="{{ $v }}" @selected($filters['jenis'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select></label>
                @if ($tab === 'klaim')
                    <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Status</span>
                        <select name="status" class="field">
                            @foreach (['' => 'Semua', 'proses' => 'Proses', 'pending' => 'Pending', 'klaim' => 'Klaim'] as $v => $l)
                                <option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>
                            @endforeach
                        </select></label>
                @endif
                <label class="flex min-w-48 flex-1 flex-col gap-1"><span class="text-xs text-ink-muted">Cari</span>
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="{{ $tab === 'klaim' ? 'No SEP, nama, no RM' : 'No SEP, nama' }}" class="field"></label>
                <button type="submit" class="btn-primary">Terapkan</button>
                <a href="{{ route('bpjs.csv', ['tab' => $tab] + $base) }}" class="btn-secondary" data-csv>Unduh CSV</a>
            </form>

            <x-panel class="overflow-x-auto">
                @if ($rows->isEmpty())
                    <x-empty-state title="Tidak ada data untuk filter ini."/>
                @elseif ($tab === 'klaim')
                    <table class="w-full text-sm">
                        <thead class="border-b border-line text-left text-xs text-ink-muted">
                            <tr>
                                <th class="px-3 py-2 font-medium">Tgl pulang</th><th class="px-3 py-2 font-medium">Pasien</th><th class="px-3 py-2 font-medium">Jenis</th>
                                <th class="px-3 py-2 font-medium">INA-CBG</th><th class="px-3 py-2 text-right font-medium">Diajukan</th><th class="px-3 py-2 text-right font-medium">Disetujui</th>
                                <th class="px-3 py-2 text-right font-medium">Selisih</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium">Lama di status</th><th class="px-3 py-2 font-medium">Folder</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rows as $r)
                                @php $sel = \App\Http\Controllers\BpjsController::selisih($r); $lama = \App\Http\Controllers\BpjsController::lama($r); $f = $folders[$r->no_sep] ?? null; @endphp
                                <tr class="cursor-pointer hover:bg-gray-50" data-row="{{ $r->no_sep }}" onclick="if (!event.target.closest('a')) location.href='{{ route('bpjs.klaim.show', $r->no_sep) }}'">
                                    <td class="tabular whitespace-nowrap px-3 py-2">{{ $r->tgl_pulang?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-3 py-2"><a href="{{ route('bpjs.klaim.show', $r->no_sep) }}" class="font-semibold text-ink">{{ $r->nama ?: '—' }}</a>
                                        <span class="tabular block text-xs text-ink-muted">RM {{ $r->no_mr ?: '—' }} · SEP {{ $r->no_sep }}</span></td>
                                    <td class="px-3 py-2">{{ $r->jns_pelayanan }}</td>
                                    <td class="px-3 py-2"><span class="font-mono">{{ $r->inacbg_kode ?: '—' }}</span>
                                        @if ($r->inacbg_nama)<span class="block text-xs text-ink-muted">{{ $r->inacbg_nama }}</span>@endif</td>
                                    <td class="tabular px-3 py-2 text-right">{{ $rp($r->by_pengajuan) }}</td>
                                    <td class="tabular px-3 py-2 text-right">{{ $rp($r->by_setujui) }}</td>
                                    <td @class(['tabular px-3 py-2 text-right', 'font-semibold text-kritis' => $sel !== null && $sel < 0]) @if ($sel !== null && $sel < 0) data-selisih-minus @endif>{{ $rp($sel) }}</td>
                                    <td class="px-3 py-2">@include('folders.partials.bpjs-chip', ['kode' => $r->status_kode])</td>
                                    <td class="tabular whitespace-nowrap px-3 py-2">{{ $lama === null ? '—' : "$lama hari" }}</td>
                                    <td class="px-3 py-2">@if ($f)<a href="{{ route('folders.show', $f->id) }}" class="text-biru-aktif underline" data-folder-link>Buka folder</a>@else — @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <table class="w-full text-sm">
                        <thead class="border-b border-line text-left text-xs text-ink-muted">
                            <tr>
                                <th class="px-3 py-2 font-medium">Tgl SEP</th><th class="px-3 py-2 font-medium">Tgl pulang</th><th class="px-3 py-2 font-medium">Pasien</th>
                                <th class="px-3 py-2 font-medium">Jenis</th><th class="px-3 py-2 font-medium">Diagnosa</th><th class="px-3 py-2 font-medium">Ada folder di FTP?</th><th class="px-3 py-2 font-medium">Status klaim</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($rows as $r)
                                <tr data-row="{{ $r->no_sep }}">
                                    <td class="tabular whitespace-nowrap px-3 py-2">{{ $r->tgl_sep?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="tabular whitespace-nowrap px-3 py-2">{{ $r->tgl_plg_sep?->format('d/m/Y') ?? '—' }}</td>
                                    <td class="px-3 py-2"><a href="{{ route('bpjs.klaim.show', $r->no_sep) }}" class="font-semibold text-ink">{{ $r->nama ?: '—' }}</a>
                                        <span class="tabular block text-xs text-ink-muted">SEP {{ $r->no_sep }}</span></td>
                                    <td class="px-3 py-2">{{ $r->jns_pelayanan }}</td>
                                    <td class="px-3 py-2">{{ $r->diagnosa ?: '—' }}</td>
                                    <td class="px-3 py-2">@if (isset($folders[$r->no_sep]))<a href="{{ route('folders.show', $folders[$r->no_sep]->id) }}" class="text-biru-aktif underline" data-folder-link>ya</a>@else tidak @endif</td>
                                    <td class="px-3 py-2">@if (isset($klaimStatus[$r->no_sep]))@include('folders.partials.bpjs-chip', ['kode' => $klaimStatus[$r->no_sep]])@else<span class="text-xs text-ink-muted">Belum terlihat di data klaim</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-panel>
            {{ $rows->links() }}
        @endif
    @endif
</div>
</x-app-layout>
