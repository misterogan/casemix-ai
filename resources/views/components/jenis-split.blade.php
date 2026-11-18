{{-- Rawat inap vs rawat jalan berdampingan. :data = BpjsStats::perJenis($periode). Tautan ke Daftar klaim /bpjs terfilter jenis & status. --}}
@props(['data'])
@php
    use App\Services\Dashboard\BpjsStats;
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pctf = fn ($n) => $n === null ? '—' : number_format($n, 1, ',', '.') . '%';
    $rentang = ['dari' => $data['dari'], 'sampai' => $data['sampai']];
    $klaimUrl = fn (string $g, ?string $status = null) => route('bpjs.index', ['tab' => 'klaim', 'jenis' => $g] + array_filter(['status' => $status]) + $rentang);
    $belumUrl = fn (string $g) => route('bpjs.index', ['tab' => 'sep', 'belum' => 1, 'jenis' => $g] + $rentang);
    $svg = fn (string $d) => new \Illuminate\Support\HtmlString('<svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>');
    $kepala = [
        'inap' => ['bg-brand-50 text-brand-600 dark:bg-brand-500/10', '<path d="M2 4v16M2 8h18a2 2 0 0 1 2 2v10M2 17h20M6 8v9"/><circle cx="7" cy="11" r="1.5"/>'],
        'jalan' => ['bg-success-50 text-success-600 dark:bg-success-500/10', '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6 6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6 6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>'],
    ];
    $sel = ['layak' => ['Layak', 'bg-success-500'], 'proses' => ['Proses', 'bg-warning-400'], 'pending' => ['Pending', 'bg-orange-500'], 'tidak_layak' => ['Tidak layak', 'bg-error-500']];
@endphp
<section {{ $attributes->class('grid grid-cols-1 gap-4 md:gap-6 lg:grid-cols-2') }} aria-label="Rawat inap dan rawat jalan" data-jenis-split>
    @foreach (['inap', 'jalan'] as $g)
        @php $d = $data[$g]; $tot = max(1, $d['klaim_total']); @endphp
        <x-card lapang class="flex h-full min-w-0 flex-col" data-jenis-kartu="{{ $g }}">
            <x-slot:header>
                <div class="flex min-w-0 items-center gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-[10px] {{ $kepala[$g][0] }}">{{ $svg($kepala[$g][1]) }}</span>
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $d['label'] }}</h3>
                        <p class="num text-xs text-gray-500" data-jenis-ringkas>{{ $num($d['sep_terbit']) }} SEP · {{ $num($d['klaim_total']) }} klaim</p>
                    </div>
                </div>
            </x-slot:header>
            <x-slot:actions><a href="{{ $klaimUrl($g) }}" class="text-xs font-medium text-brand-600 hover:underline" data-lihat-daftar>Lihat daftar</a></x-slot:actions>

            @if ($d['kosong'])
                <x-empty-state class="!py-6" title="Belum ada data {{ strtolower($d['label']) }} untuk periode ini." data-jenis-kosong/>
            @else
                {{-- Bar status tersegmentasi --}}
                <div class="flex h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="img"
                     aria-label="{{ collect($sel)->map(fn ($s, $k) => $s[0] . ' ' . $pctf($d['status'][$k]['pct']))->implode(', ') }}">
                    @foreach ($sel as $k => [$l, $warna])
                        @if ($d['status'][$k]['n'] > 0)<span class="{{ $warna }} h-full" style="width: {{ round($d['status'][$k]['n'] * 100 / $tot, 2) }}%"></span>@endif
                    @endforeach
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                    @foreach ($sel as $k => [$l, $warna])<li class="flex items-center gap-1.5"><span class="size-2 rounded-full {{ $warna }}"></span>{{ $l }}</li>@endforeach
                </ul>

                {{-- 2×2 angka --}}
                <div class="mt-5 grid grid-cols-2 gap-3">
                    @foreach (['layak', 'pending', 'tidak_layak', 'proses'] as $k)
                        @php
                            $s = $d['status'][$k];
                            $delta = $s['delta'];
                            $baik = $delta !== null && $delta !== 0 && (($k === 'layak') === ($delta > 0));
                        @endphp
                        <a href="{{ $klaimUrl($g, $s['kategori']) }}" class="rounded-xl border border-gray-100 p-3 hover:border-brand-300 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-white/5"
                           data-jenis-sel="{{ $k }}" data-n="{{ $s['n'] }}">
                            <p class="text-xs text-gray-500">{{ $sel[$k][0] }}</p>
                            <p class="mt-1 flex flex-wrap items-baseline gap-x-2">
                                <span class="num text-[22px] font-semibold leading-tight text-gray-800 tabular-nums dark:text-white/90">{{ $num($s['n']) }}</span>
                                <span class="text-xs text-gray-500">{{ $pctf($s['pct']) }}</span>
                                @if ($delta)
                                    <span @class(['rounded-full px-1.5 py-0.5 text-[11px] font-medium tabular-nums', 'bg-success-50 text-success-700' => $baik, 'bg-error-50 text-error-700' => ! $baik])
                                          title="Dibanding periode sebelumnya" data-tren="{{ $baik ? 'baik' : 'buruk' }}">{{ $delta > 0 ? '▲' : '▼' }} {{ $num(abs($delta)) }}</span>
                                @endif
                            </p>
                        </a>
                    @endforeach
                </div>

                {{-- Nilai --}}
                <dl class="mt-5 grid grid-cols-3 divide-x divide-gray-100 border-y border-gray-100 py-3 text-center dark:divide-gray-800 dark:border-gray-800">
                    @foreach (['diajukan' => 'Diajukan', 'disetujui' => 'Disetujui', 'tertahan' => 'Tertahan'] as $k => $l)
                        @php $v = $d['nilai'][$k]; @endphp
                        <div class="px-2">
                            <dt class="text-xs text-gray-500">{{ $l }}</dt>
                            <dd @class(['num mt-0.5 text-sm font-semibold tabular-nums', 'text-error-600' => $k === 'tertahan' && $v > 0, 'text-gray-800 dark:text-white/90' => ! ($k === 'tertahan' && $v > 0)])
                                title="{{ BpjsStats::rupiahPenuh($v) }}" data-nilai="{{ $k }}">{{ BpjsStats::rupiahRingkas($v) }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($d['tagihan_belum_tercatat'] > 0)
                    <a href="{{ $belumUrl($g) }}" class="mt-4 inline-flex" data-jenis-belum><x-badge variant="warning">{{ $num($d['tagihan_belum_tercatat']) }} sudah pulang, tagihan belum tercatat</x-badge></a>
                @endif

                {{-- Bagian khusus --}}
                <div class="mt-5">
                    @if ($g === 'inap')
                        <div data-jenis-severity>
                            <p class="text-xs font-medium text-gray-500">Severity · % pending</p>
                            <ul class="mt-2 space-y-2">
                                @foreach ($d['severity'] as $sv => $x)
                                    <li class="grid grid-cols-[2.5rem_1fr_3.5rem] items-center gap-3 text-xs">
                                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $sv }}</span>
                                        <span class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-orange-500" style="width: {{ $x['pct_pending'] ?? 0 }}%"></span></span>
                                        <span class="num text-right tabular-nums text-gray-600" title="{{ $num($x['n']) }} klaim">{{ $pctf($x['pct_pending']) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-500">
                                <span data-los>LOS median <strong class="num text-gray-800 dark:text-white/90">{{ $d['los']['median'] === null ? '—' : str_replace('.', ',', (string) $d['los']['median']) }}</strong> hari</span>
                                <span data-readmisi>Readmisi <strong class="num text-gray-800 dark:text-white/90">{{ $pctf($d['readmisi']['pct']) }}</strong></span>
                            </div>
                        </div>
                    @else
                        <div data-jenis-poli>
                            <p class="text-xs font-medium text-gray-500">Poli teratas · % pending</p>
                            @if (empty($d['poli']))
                                <p class="mt-2 text-xs text-gray-500">Belum ada data poli.</p>
                            @else
                                <ul class="mt-2 space-y-2">
                                    @foreach ($d['poli'] as $x)
                                        <li class="grid grid-cols-[minmax(0,7rem)_1fr_3.5rem] items-center gap-3 text-xs">
                                            <span class="truncate font-medium text-gray-700 dark:text-gray-300" title="{{ $x['poli'] }} · {{ $num($x['n']) }} klaim">{{ $x['poli'] }}</span>
                                            <span class="h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-orange-500" style="width: {{ $x['pct_pending'] ?? 0 }}%"></span></span>
                                            <span class="num text-right tabular-nums text-gray-600">{{ $pctf($x['pct_pending']) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </x-card>
    @endforeach
</section>
