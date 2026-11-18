@props(['judul', 'data', 'dasar' => [], 'tone' => 'netral', 'chip' => null, 'arti' => null, 'tindak' => null, 'id' => null])
@php
    use App\Services\Dashboard\BpjsStats;
    $tema = [
        'amber' => ['bg-triage-amber-soft', 'bg-triage-amber', 'text-triage-amber'],
        'green' => ['bg-triage-green-soft', 'bg-triage-green', 'text-triage-green'],
        'red' => ['bg-triage-red-soft', 'bg-triage-red', 'text-triage-red'],
        'netral' => ['bg-white border border-line', 'bg-ink-muted', 'text-ink'],
    ][$tone] ?? ['bg-white border border-line', 'bg-ink-muted', 'text-ink'];
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $fmt = fn ($v) => match ($data['format'] ?? 'angka') {
        'rupiah' => BpjsStats::rupiahRingkas((float) $v),
        'hari' => number_format((float) $v, 1, ',', '.'),
        default => $num($v),
    };
    $sat = ['rupiah' => '', 'hari' => 'hari'][$data['format'] ?? 'angka'] ?? '×';
    $maks = max(1, ...array_map(fn ($i) => (float) $i['nilai'], $data['items'] ?: [['nilai' => 1]]));
    $url = fn (array $f) => route('bpjs.index', array_filter($f + ($data['filter'] ?? []) + $dasar, fn ($v) => $v !== '' && $v !== null));
@endphp
<div {{ $attributes->merge(['class' => "rounded-[10px] p-5 {$tema[0]}"]) }} data-rank-list @if ($id) id="{{ $id }}" @endif>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <h3 class="flex flex-wrap items-center gap-2 font-semibold text-ink">
            @if ($chip)<span class="rounded-[6px] bg-white/70 px-2 py-0.5 text-xs font-medium {{ $tema[2] }}" data-rank-chip>{{ $chip }}</span>@endif
            {{ $judul }}
        </h3>
        <span class="tabular text-xs text-ink-muted" data-rank-total>{{ $num($data['kategori']) }} kategori · {{ $num($data['klaim']) }} klaim</span>
    </div>
    @if ($arti)<p class="mt-2 text-sm text-ink"><span class="font-medium">Artinya:</span> {{ $arti }} @if ($tindak)<span class="font-medium">Tindakan:</span> {{ $tindak }}@endif</p>@endif
    {{ $slot }}
    @if (empty($data['items']))
        <p class="mt-3 text-sm text-ink-muted" data-rank-kosong>Tidak ada data untuk periode ini.</p>
    @else
        <ol class="mt-3 space-y-1">
            @foreach ($data['items'] as $i)
                <li>
                    <a href="{{ $url($i['filter'] ?? []) }}" data-rank-row="{{ $i['kode'] }}"
                       class="grid grid-cols-[auto_1fr_auto] items-center gap-3 rounded-[6px] px-2 py-1.5 text-sm hover:bg-white/80 focus:outline-none focus-visible:ring-2 focus-visible:ring-biru-aktif sm:grid-cols-[auto_1fr_150px_auto]">
                        <span class="tabular rounded-[6px] border border-line bg-white px-1.5 py-0.5 text-xs font-semibold text-ink">{{ $i['kode'] }}</span>
                        <span class="min-w-0 truncate text-ink" title="{{ $i['nama'] }}">{{ $i['nama'] ?: '—' }}
                            @if (! empty($i['chip']))<span class="ml-1 rounded-[6px] bg-white/70 px-1.5 py-0.5 text-xs text-ink-muted" data-rank-row-chip>{{ $i['chip'] }}</span>@endif</span>
                        <span class="hidden h-1.5 rounded-full bg-black/5 sm:block" aria-hidden="true"><span class="block h-1.5 rounded-full {{ $tema[1] }}" style="width: {{ round((float) $i['nilai'] * 100 / $maks, 1) }}%"></span></span>
                        <span class="tabular text-right font-medium text-ink" @if (($data['format'] ?? '') === 'rupiah') title="{{ BpjsStats::rupiahPenuh((float) $i['nilai']) }}" @endif>{{ $fmt($i['nilai']) }}@if ($sat)<span class="ml-0.5 text-xs font-normal text-ink-muted">{{ $sat }}</span>@endif</span>
                    </a>
                </li>
            @endforeach
        </ol>
        @if ($data['lain_kategori'] > 0)
            <a href="{{ $url([]) }}" class="mt-2 inline-block text-xs text-biru-aktif underline" data-rank-lain>+ {{ $num($data['lain_kategori']) }} kategori lain · {{ $num($data['lain_klaim']) }} klaim</a>
        @endif
    @endif
</div>
