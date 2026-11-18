@php
    use App\Services\Dashboard\BpjsStats;
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pctf = fn ($n) => $n === null ? '' : number_format($n, 1, ',', '.') . '%';
    $jam = fn ($w) => $w ? \Illuminate\Support\Carbon::parse($w)->format('d/m/Y H:i') : 'belum pernah';
    $rr = fn ($n) => BpjsStats::rupiahRingkas((float) $n);
    $rf = fn ($n) => BpjsStats::rupiahPenuh((float) $n);
    $samar = fn (?string $n) => $n ? collect(preg_split('/\s+/', trim($n)))->map(fn ($w) => mb_substr($w, 0, 1) . str_repeat('•', max(2, min(5, mb_strlen($w) - 1))))->implode(' ') : '—';
    $t = $stats['tren'];
    $ds = $stats['donut_status'];
    $dj = $stats['donut_jenis'];
    $payload = ['donut_status' => $ds + ['links' => $links['status']], 'donut_jenis' => $dj + ['links' => $links['jenis']],
        'tren' => $t + ['links' => $links['tren']], 'status_keys' => array_keys(BpjsStats::STATUS), 'status_label' => BpjsStats::STATUS_LABEL,
        'los' => $stats['analisis']['mutu']['los'] ?? null, 'readmisi' => $stats['analisis']['mutu']['readmisi']['tren'] ?? null];
    $warnaStatus = ['proses' => 'bg-warning-500', 'pending' => 'bg-error-500', 'tidak_layak' => 'bg-[#7F1D1D]', 'dispute' => 'bg-[#6B21A8]',
        'klaim' => 'bg-success-500', 'lainnya' => 'bg-gray-400'];
    $diajukan = array_sum($ds['rp']);
    $layakKini = $t['diajukan'][11] ?? 0;
    $layakLalu = $t['diajukan'][10] ?? 0;
    $trenHero = $layakLalu > 0 ? round(($layakKini - $layakLalu) * 100 / $layakLalu, 1) : null;
    $dari = $dasarLink['dari'] ?? null;
    $sampai = $dasarLink['sampai'] ?? null;
@endphp

<div class="grid grid-cols-12 gap-4 md:gap-6">
    {{-- Baris 1: hero + 2×2 stat --}}
    <section class="col-span-12 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 xl:col-span-5 dark:border-gray-800 dark:bg-white/[.03]" data-hero>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-white/90">Pusat kendali klaim BPJS</h2>
            <form method="GET" action="{{ route('bpjs.index') }}" class="flex flex-wrap items-center gap-2 text-xs" data-filter-ringkasan>
                <input type="hidden" name="tab" value="ringkasan">
                <label class="sr-only" for="f-periode">Periode (bulan tgl pulang)</label>
                <input id="f-periode" type="month" name="periode" value="{{ $periode }}" onchange="this.form.submit()" class="field num h-8 rounded-lg px-2 py-1 text-xs">
                <label class="sr-only" for="f-jenis">Jenis</label>
                <select id="f-jenis" name="jenis" onchange="this.form.submit()" class="field h-8 rounded-lg px-2 py-1 text-xs">
                    @foreach (['' => 'Semua', 'inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'] as $v => $l)
                        <option value="{{ $v }}" @selected($jenisRingkasan === $v)>{{ $l }}</option>
                    @endforeach
                </select>
                <noscript><button type="submit" class="btn-primary">Terapkan</button></noscript>
            </form>
        </div>

        @if (! $stats['kosong'])
            <p class="tabular mt-3 text-sm leading-relaxed text-gray-600 dark:text-gray-300" data-kalimat>@foreach ($stats['kalimat'] as $p)@if (isset($p['b']))<strong class="font-semibold text-gray-800 dark:text-white/90" @if (isset($p['title'])) title="{{ $p['title'] }}" @endif>{{ $p['b'] }}</strong>@else{{ $p['t'] }}@endif @endforeach</p>
            <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Nilai diajukan</p>
                    <p class="num mt-1 text-[34px] leading-10 font-semibold text-gray-800 dark:text-white/90" title="{{ $rf($diajukan) }}" data-diajukan>{{ $rr($diajukan) }}</p>
                    @if ($trenHero !== null)
                        <span @class(['badge mt-2', 'tone-ok' => $trenHero >= 0, 'tone-crit' => $trenHero < 0]) title="Nilai klaim layak diajukan dibanding bulan lalu">
                            <x-icon :name="$trenHero >= 0 ? 'arrow-up' : 'arrow-down'" class="size-3"/><span class="num">{{ str_replace('.', ',', (string) abs($trenHero)) }}%</span> nilai layak
                        </span>
                    @endif
                </div>
                <div class="h-14 w-36 sm:w-44"><canvas id="chart-spark" role="img" aria-label="Nilai klaim layak diajukan 12 bulan terakhir"></canvas></div>
            </div>
            @php $tot = max(1, $stats['total_klaim']); @endphp
            <div class="mt-5" data-bar-status>
                <div class="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="img"
                     aria-label="Komposisi status: {{ collect($ds['keys'])->map(fn ($k, $i) => $ds['labels'][$i] . ' ' . $ds['counts'][$i])->implode(', ') }}">
                    @foreach ($ds['keys'] as $i => $k)
                        @if ($ds['counts'][$i] > 0)<div class="{{ $warnaStatus[$k] }}" style="width: {{ round($ds['counts'][$i] * 100 / $tot, 2) }}%"></div>@endif
                    @endforeach
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                    @foreach ($ds['keys'] as $i => $k)
                        @continue($k === 'lainnya' && ! $ds['counts'][$i])
                        <li class="flex items-center gap-1.5" data-legenda="{{ $k }}"><span class="inline-block size-2 rounded-full {{ $warnaStatus[$k] }}"></span>
                            {{ $ds['labels'][$i] }} <span class="num font-medium text-gray-800 dark:text-white/90">{{ $num($ds['counts'][$i]) }}</span></li>
                    @endforeach
                </ul>
            </div>
            <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-gray-100 pt-4 text-xs dark:border-gray-800">
                @foreach (['sep', 'proses', 'kurang'] as $k)
                    @php $c = $stats['kartu'][$k]; @endphp
                    <a href="{{ $links['kartu'][$k] }}" data-kartu="{{ $k }}" class="min-w-0 rounded-lg hover:text-brand-500">
                        <dt class="truncate text-gray-500 dark:text-gray-400" title="{{ $c['label'] }}">{{ $c['label'] }}</dt>
                        <dd class="num mt-0.5 text-base font-semibold text-gray-800 dark:text-white/90" data-angka>{{ $num($c['n']) }}</dd>
                    </a>
                @endforeach
            </dl>
        @endif
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            <span class="badge tone-ok" data-chip-resmi>Nilai resmi VClaim</span>
            <span class="badge tone-neutral" data-chip-ditarik>Ditarik {{ $jam($lastSync) }}</span>
        </div>
    </section>

    @if (! $stats['kosong'])
        @php
            $statKartu = ['klaim' => ['check', 'success'], 'pending' => ['clock', 'error'], 'tolak' => ['x', 'gray'], 'belum' => ['alert', 'warning']];
            $dasarPct = ['belum' => 'SEP terbit'];
        @endphp
        <div class="col-span-12 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:col-span-7" data-kartu-status>
            @foreach ($statKartu as $k => [$ikon, $tone])
                @php
                    $c = $stats['kartu'][$k];
                    $d = $c['delta'];
                    $baik = $d == 0 || $c['naik_baik'] === null ? null : (($d > 0) === $c['naik_baik']);
                @endphp
                <x-stat-card :label="$c['label']" :value="$num($c['n'])" :icon="$ikon" :tone="$tone" :href="$links['kartu'][$k]" data-kartu="{{ $k }}">
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span @class(['badge num', 'tone-ok' => $baik === true, 'tone-crit' => $baik === false, 'tone-neutral' => $baik === null]) data-delta>
                            @if ($d != 0)<x-icon :name="$d > 0 ? 'arrow-up' : 'arrow-down'" class="size-3"/>@endif{{ $d > 0 ? '+' . $num($d) : ($d < 0 ? '−' . $num(abs($d)) : 'sama') }} vs bulan lalu</span>
                        @if ($c['pct'] !== null)<span class="text-gray-500 dark:text-gray-400"><span class="num">{{ $pctf($c['pct']) }}</span> dari {{ $dasarPct[$k] ?? 'total klaim' }}</span>@endif
                        @if ($c['rp'] !== null)<span class="num text-gray-500 dark:text-gray-400" title="{{ $rf($c['rp']) }}">{{ $rr($c['rp']) }} diajukan</span>@endif
                    </div>
                    @if ($k === 'belum')
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-belum-catatan>SEP sudah terbit (pelayanan dijamin BPJS), tetapi tagihan klaimnya belum tercatat di BPJS. Bisa belum diajukan dari E-Klaim atau belum diproses.</p>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-belum-umur>@foreach ($stats['aksi']['belum_umur'] ?? [] as $u => $nu)<span class="num">{{ $num($nu) }}</span> {{ $u }}@if (! $loop->last) · @endif @endforeach</p>
                    @endif
                    @if ($k === 'tolak')<p class="mt-2 text-xs text-gray-500 dark:text-gray-400" data-tolak-catatan>Alasan tidak tersedia di VClaim. Lihat umpan balik verifikator.</p>@endif
                </x-stat-card>
            @endforeach
        </div>
    @else
        <div class="col-span-12 xl:col-span-7"><x-card><x-empty-state title="Belum ada data BPJS untuk periode ini." data-bpjs-kosong/></x-card></div>
    @endif

    {{-- Rawat inap vs rawat jalan: disembunyikan bila filter jenis aktif --}}
    @if ($jenisRingkasan === '')
        <x-jenis-split class="col-span-12" :data="app(BpjsStats::class)->perJenis($periode)"/>
    @endif

    {{-- Status sinkron + berkas FTP --}}
    <div class="col-span-12 flex flex-wrap items-stretch gap-4 md:gap-6">
        <div class="grid min-w-0 flex-1 gap-3 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm md:grid-cols-2 dark:border-gray-800 dark:bg-white/[.03]" data-sinkron>
            @foreach (['klaim' => 'Data klaim', 'kunjungan' => 'Data kunjungan'] as $j => $judul)
                @php $s = $sinkron[$j] ?? null; @endphp
                <p class="flex items-start gap-2" data-sinkron-{{ $j }}>
                    <span class="mt-1.5 inline-block size-2 shrink-0 rounded-full {{ $s === null ? 'bg-gray-400' : ($s['ok'] ? 'bg-triage-green' : 'bg-triage-red') }}" aria-hidden="true"></span>
                    <span><span class="font-medium text-gray-800 dark:text-white/90">{{ $judul }}</span>
                        <span class="tabular text-gray-500 dark:text-gray-400"> · terakhir {{ $jam($s['waktu'] ?? null) }}@if ($s)
                            · @if ($j === 'klaim'){{ $num($s['dicek']) }} SEP dicek, {{ $num($s['berubah']) }} status berubah @else{{ $num($s['baru']) }} SEP baru @endif
                        @endif</span>
                        @if ($s && ! $s['ok'])<span class="block text-xs text-error-600" data-sinkron-gagal>Tarikan terakhir gagal{{ $s['error'] ? ': ' . $s['error'] : '' }}</span>@endif
                    </span>
                </p>
            @endforeach
        </div>
        @isset($tanpaFolder)
            <a href="{{ $tanpaFolder['url'] }}" data-kartu="tanpa_folder" class="flex min-w-64 items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white px-5 py-3 text-sm hover:border-brand-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:border-gray-800 dark:bg-white/[.03]">
                <span><span class="font-medium text-gray-800 dark:text-white/90">Berkas belum ada di FTP</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">SEP pulang lebih dari 7 hari lalu tanpa folder berkas di FTP</span></span>
                <span class="num text-2xl font-semibold text-gray-800 dark:text-white/90" data-angka>{{ $num($tanpaFolder['n']) }}</span>
            </a>
        @endisset
    </div>

    @unless ($stats['kosong'])
        {{-- Baris 2: tren + donut status --}}
        <x-card class="col-span-12 min-w-0 xl:col-span-8" title="Tren status klaim" subtitle="Batang bertumpuk per status; garis putus-putus = persen pending (sumbu kanan).">
            <x-slot:actions>
                <div class="inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-800" role="group" aria-label="Rentang tren" data-tren-rentang>
                    <button type="button" class="rounded-md px-2.5 py-1 text-gray-500" data-rentang="6" aria-pressed="false">6 bulan</button>
                    <button type="button" class="rounded-md bg-white px-2.5 py-1 font-medium text-gray-800 shadow-xs dark:bg-gray-900 dark:text-white/90" data-rentang="12" aria-pressed="true">12 bulan</button>
                </div>
                <button type="button" class="text-xs text-brand-500 hover:underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-tren">Lihat tabel</button>
            </x-slot:actions>
            <div class="relative h-72"><canvas id="chart-tren" role="img" aria-label="Tren klaim {{ $t['label'][0] }} sampai {{ $t['label'][11] }}, batang bertumpuk per status dan garis persen pending. {{ $t['label'][11] }}: proses {{ $t['proses'][11] }}, pending {{ $t['pending'][11] }}, tidak layak {{ $t['tidak_layak'][11] }}, dispute {{ $t['dispute'][11] }}, klaim {{ $t['klaim'][11] }}."></canvas></div>
            <div class="overflow-x-auto"><table id="tabel-tren" hidden class="table mt-3 min-w-full">
                <thead><tr><th>Bulan</th><th class="text-right">Proses</th><th class="text-right">Pending</th><th class="text-right">Tidak layak</th><th class="text-right">Dispute</th><th class="text-right">Klaim</th><th class="text-right">% pending</th></tr></thead>
                <tbody>
                    @foreach ($t['label'] as $i => $l)
                        <tr><td>{{ $l }}@if ($t['go_live'] === $i) <span class="text-xs text-gray-500">(CERMAT mulai)</span>@endif</td>
                            <td class="text-right">{{ $t['proses'][$i] }}</td><td class="text-right">{{ $t['pending'][$i] }}</td><td class="text-right">{{ $t['tidak_layak'][$i] }}</td><td class="text-right">{{ $t['dispute'][$i] }}</td>
                            <td class="text-right">{{ $t['klaim'][$i] }}</td><td class="text-right">{{ $pctf($t['pct_pending'][$i]) }}</td></tr>
                    @endforeach
                </tbody>
            </table></div>
        </x-card>

        @php $sum = array_sum($ds['counts']); @endphp
        <x-card class="col-span-12 min-w-0 xl:col-span-4" title="Status klaim" subtitle="Klik irisan atau baris untuk membuka daftarnya.">
            <div class="relative mx-auto h-48 max-w-48">
                <canvas id="chart-status" role="img" aria-label="Status klaim: {{ collect($ds['labels'])->map(fn ($l, $i) => $l . ' ' . $ds['counts'][$i])->implode(', ') }}; total {{ $sum }} klaim"></canvas>
                <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center" aria-hidden="true">
                    <span class="num text-2xl font-semibold text-gray-800 dark:text-white/90">{{ $num($sum) }}</span><span class="text-xs text-gray-500">klaim</span>
                </div>
            </div>
            <ul class="mt-5 divide-y divide-gray-100 text-sm dark:divide-gray-800" data-legenda-status>
                @foreach ($ds['keys'] as $i => $k)
                    @continue($k === 'lainnya' && ! $ds['counts'][$i])
                    <li><a href="{{ $links['status'][$i] }}" class="flex items-center gap-3 py-2 hover:text-brand-500">
                        <span class="size-2.5 shrink-0 rounded-full {{ $warnaStatus[$k] }}" aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-300">{{ $ds['labels'][$i] }}</span>
                        <span class="num font-medium text-gray-800 dark:text-white/90">{{ $num($ds['counts'][$i]) }}</span>
                        <span class="num w-20 text-right text-xs text-gray-500" title="{{ $rf($ds['rp'][$i]) }}">{{ $rr($ds['rp'][$i]) }}</span>
                    </a></li>
                @endforeach
            </ul>
        </x-card>

        {{-- Baris 3: nilai tertahan, severity, tindak lanjut --}}
        @php
            $pd = $stats['analisis']['pending'];
            $tert = $stats['kartu']['tertahan'];
            $warnaEmber = ['0–7 hari' => 'bg-warning-500/50', '8–14 hari' => 'bg-warning-500', '15–30 hari' => 'bg-error-500', '>30 hari' => 'bg-[#7F1D1D]'];
            $totE = max(1, array_sum($pd['ember']));
        @endphp
        <x-card class="col-span-12 min-w-0 md:col-span-6 xl:col-span-4" title="Nilai tertahan" subtitle="Nilai diajukan klaim berstatus pending, menurut umur pending." data-kartu-tertahan>
            <a href="{{ $links['kartu']['tertahan'] }}" data-kartu="tertahan" class="block">
                <span class="num block text-[34px] leading-10 font-semibold text-gray-800 dark:text-white/90" data-angka title="{{ $rf($tert['n']) }}">{{ $rr($tert['n']) }}</span>
                <span class="num mt-1 block text-xs text-gray-500" data-delta>{{ $tert['delta'] > 0 ? '+' . $rr($tert['delta']) : ($tert['delta'] < 0 ? '−' . $rr(abs($tert['delta'])) : 'sama') }} vs bulan lalu</span>
            </a>
            <div class="mt-5 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="img" aria-label="Umur pending: {{ collect($pd['ember'])->map(fn ($n, $l) => "$l $n")->implode(', ') }}" data-umur-pending>
                @foreach ($pd['ember'] as $l => $n)
                    @if ($n)<div class="{{ $warnaEmber[$l] }}" style="width: {{ round($n * 100 / $totE, 2) }}%"></div>@endif
                @endforeach
            </div>
            <ul class="mt-4 space-y-2 text-sm">
                @foreach ($pd['ember'] as $l => $n)
                    <li class="flex items-center gap-2" data-ember="{{ $l }}"><span class="size-2.5 rounded-full {{ $warnaEmber[$l] }}" aria-hidden="true"></span>
                        <span class="flex-1 text-gray-600 dark:text-gray-300">{{ $l }}</span><span class="num font-medium text-gray-800 dark:text-white/90">{{ $num($n) }}</span></li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-gray-500">Median <span class="num font-medium text-gray-800 dark:text-white/90" data-median>{{ $pd['median'] === null ? '—' : number_format($pd['median'], 1, ',', '.') }}</span> hari, rata-rata <span class="num font-medium text-gray-800 dark:text-white/90" data-rata>{{ $pd['rata'] === null ? '—' : number_format($pd['rata'], 1, ',', '.') }}</span> hari.</p>
        </x-card>

        <x-card class="col-span-12 min-w-0 md:col-span-6 xl:col-span-4" title="Severity rawat inap" data-bagian="severity">
            @if (! ($sv = $stats['analisis']['severity']))
                <p class="text-sm text-gray-500" data-tidak-berlaku>Tidak berlaku untuk rawat jalan. Pilih jenis "Semua" atau "Rawat inap".</p>
            @else
                <p class="-mt-2 mb-4 text-xs text-gray-500">Dari akhiran kode INA-CBG. Rata-rata pending rawat inap <span class="num">{{ BpjsStats::pctTeks($sv['rata_pending']) }}</span>.</p>
                <ul class="space-y-4" data-kartu-severity>
                    @foreach ($sv['kartu'] as $s => $c)
                        <li><a href="{{ route('bpjs.index', $dasarLink + ['sev' => $s]) }}" data-sev="{{ $s }}" class="block rounded-lg hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex items-center justify-between gap-2 text-sm">
                                <span class="font-medium text-gray-800 dark:text-white/90">Severity {{ $s }} <span class="num ml-1 text-xs font-normal text-gray-500">{{ $num($c['n']) }} · {{ BpjsStats::pctTeks($c['pct_inap']) }}</span></span>
                                <span @class(['badge num', 'tone-crit' => $c['tinggi'], 'tone-neutral' => ! $c['tinggi']]) @if ($c['tinggi']) data-sev-tinggi @endif>{{ BpjsStats::pctTeks($c['pct_pending']) }} pending</span>
                            </div>
                            <div class="mt-2 h-2 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-2 rounded-full {{ $c['tinggi'] ? 'bg-error-500' : 'bg-brand-500' }}" style="width: {{ min(100, (float) $c['pct_inap']) }}%"></div></div>
                        </a></li>
                    @endforeach
                </ul>
            @endif
        </x-card>

        @php
            $aksi = [
                'pending14' => ['Pending lebih dari 14 hari', 'klaim tertahan dan berisiko melewati batas tanggapan.', 'periksa umpan balik verifikator, lengkapi berkas, ajukan ulang.', 'tone-crit'],
                'belum7' => ['Sudah pulang, tagihan belum tercatat di BPJS (>7 hari sejak pulang)', 'Layanan sudah diberikan, tetapi klaimnya belum sampai ke BPJS.',
                    'Cek di E-Klaim apakah sudah diajukan; yang lebih dari 30 hari sejak pulang didahulukan.', 'tone-warn'],
                'tidak_layak' => ['Klaim tidak layak', 'BPJS menolak klaim ini.', 'telusuri alasan dari verifikator, nilai apakah layak diajukan dispute.', 'tone-crit'],
            ];
        @endphp
        <x-card class="col-span-12 min-w-0 xl:col-span-4" title="Perlu tindak lanjut" data-panel-aksi>
            <ul class="space-y-3">
                @foreach ($aksi as $k => [$judul, $arti, $tindak, $tone])
                    @php $n = $stats['aksi'][$k]; @endphp
                    @continue($k === 'tidak_layak' && $n === 0)
                    @if ($n === 0)
                        <li class="flex items-center gap-3 rounded-xl bg-success-50 px-3 py-2 dark:bg-success-500/10" data-aksi="{{ $k }}" data-aksi-aman>
                            <span class="num text-lg font-semibold text-success-600">0</span>
                            <span class="min-w-0 text-xs"><span class="block font-medium text-gray-800 dark:text-white/90">{{ $judul }}</span><span class="text-success-600">Tidak ada yang perlu dikejar</span></span>
                        </li>
                    @else
                        <li class="rounded-xl border border-gray-100 px-3 py-2 dark:border-gray-800" data-aksi="{{ $k }}">
                            <div class="flex items-start gap-3">
                                <span class="badge num {{ $tone }}" data-aksi-n>{{ $num($n) }}</span>
                                <div class="min-w-0 flex-1 text-xs">
                                    <p class="font-medium text-gray-800 dark:text-white/90">{{ $judul }}</p>
                                    @if ($k === 'pending14' && $stats['aksi']['pending14_rp'] > 0)<p class="num text-gray-500" title="{{ $rf($stats['aksi']['pending14_rp']) }}">{{ $rr($stats['aksi']['pending14_rp']) }} nilai tertahan</p>@endif
                                    @if ($k === 'tidak_layak')<p class="num text-gray-500" title="{{ $rf($stats['aksi']['tidak_layak_rp']) }}" data-aksi-rp>{{ $rr($stats['aksi']['tidak_layak_rp']) }} diajukan</p>@endif
                                    <p class="mt-1 text-gray-600 dark:text-gray-400"><span class="font-medium">Artinya:</span> {{ $arti }} <span class="font-medium">Tindakan:</span> {{ $tindak }}</p>
                                    <a href="{{ $links['aksi'][$k] }}" class="mt-1 inline-block text-brand-500 hover:underline" data-aksi-link>Lihat daftar</a>
                                </div>
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
            <ul class="mt-4 divide-y divide-gray-100 dark:divide-gray-800" data-pending-terlama>
                @forelse (array_slice($pd['terlama'], 0, 5) as $r)
                    <li><a href="{{ route('bpjs.klaim.show', $r['no_sep']) }}" class="flex items-center gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-error-50 text-error-600 dark:bg-error-500/10" aria-hidden="true"><x-icon name="clock" class="size-4"/></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90" title="SEP {{ $r['no_sep'] }}">{{ $samar($r['nama']) }}</span>
                            <span class="num block truncate text-xs text-gray-500">{{ $r['inacbg_kode'] ?: '—' }} · pending {{ $num($r['umur']) }} hari</span>
                        </span>
                        <span class="text-right">
                            <span class="num block text-sm font-medium text-gray-800 dark:text-white/90" @if ($r['by_pengajuan'] !== null) title="{{ $rf($r['by_pengajuan']) }}" @endif>{{ $r['by_pengajuan'] !== null ? $rr($r['by_pengajuan']) : '—' }}</span>
                            <span class="num block text-xs text-gray-500">{{ $r['tgl_pulang'] ? \Illuminate\Support\Carbon::parse($r['tgl_pulang'])->format('d/m/Y') : '—' }}</span>
                        </span>
                    </a></li>
                @empty
                    <li class="py-3 text-sm text-gray-500">Tidak ada klaim pending pada periode ini.</li>
                @endforelse
            </ul>
            <a href="{{ $links['kartu']['pending'] }}" class="btn-secondary mt-4 flex w-full justify-center">Lihat semua</a>
        </x-card>

        {{-- Baris 4: klaim terbaru --}}
        @php
            $qTerbaru = \App\Models\VclaimKlaim::query()->when($dari && $sampai, fn ($q) => $q->whereBetween('tgl_pulang', [$dari, $sampai]));
            BpjsStats::jenis($qTerbaru, $jenisRingkasan);
            $terbaru = $qTerbaru->orderByDesc('tgl_pulang')->orderByDesc('id')->limit(8)->get();
            $badgeStatus = ['proses' => 'warning', 'pending' => 'error', 'tidak_layak' => 'error', 'dispute' => 'brand', 'klaim' => 'success', 'lainnya' => 'gray'];
        @endphp
        <x-card class="col-span-12 min-w-0" :padding="false" data-klaim-terbaru>
            <x-slot:header>
                <div class="min-w-0"><h3 class="text-base font-semibold text-gray-800 dark:text-white/90">Klaim terbaru</h3>
                    <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Menurut tanggal pulang pada periode ini.</p></div>
            </x-slot:header>
            <x-slot:actions>
                <form method="GET" action="{{ route('bpjs.index') }}" class="flex items-center gap-2" role="search">
                    @foreach ($dasarLink as $h => $v)<input type="hidden" name="{{ $h }}" value="{{ $v }}">@endforeach
                    <label class="relative"><span class="sr-only">Cari klaim</span>
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-gray-400"/>
                        <input type="search" name="q" placeholder="No SEP, nama, no RM" class="field h-9 w-full rounded-lg pl-9 text-sm sm:w-56"></label>
                </form>
                <a href="{{ route('bpjs.index', $dasarLink) }}" class="btn-secondary h-9">Filter</a>
            </x-slot:actions>
            @if ($terbaru->isEmpty())
                <p class="px-6 py-6 text-sm text-gray-500">Belum ada klaim pada periode ini.</p>
            @else
                <x-table class="overflow-x-auto">
                    <x-slot:head><tr><th>No. SEP</th><th>Pasien</th><th>INA-CBG</th><th class="text-right">Diajukan</th><th>Tanggal pulang</th><th>Status</th><th class="w-10"><span class="sr-only">Aksi</span></th></tr></x-slot:head>
                    @foreach ($terbaru as $r)
                        <tr data-terbaru="{{ $r->no_sep }}">
                            <td class="num whitespace-nowrap">{{ $r->no_sep }}</td>
                            <td><span class="font-medium text-gray-800 dark:text-white/90">{{ $samar($r->nama) }}</span><span class="num block text-xs text-gray-500">RM {{ $r->no_mr ?: '—' }}</span></td>
                            <td class="num whitespace-nowrap">{{ $r->inacbg_kode ?: '—' }}</td>
                            <td class="num text-right whitespace-nowrap" @if ($r->by_pengajuan !== null) title="{{ $rf($r->by_pengajuan) }}" @endif>{{ $r->by_pengajuan !== null ? $rr($r->by_pengajuan) : '—' }}</td>
                            <td class="num whitespace-nowrap">{{ $r->tgl_pulang?->format('d/m/Y') ?? '—' }}</td>
                            <td><x-badge :variant="$badgeStatus[$r->status_kategori] ?? 'gray'">{{ BpjsStats::STATUS_LABEL[$r->status_kategori] ?? 'Lainnya' }}</x-badge></td>
                            <td><a href="{{ route('bpjs.klaim.show', $r->no_sep) }}" class="grid size-8 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Buka klaim {{ $r->no_sep }}"><x-icon name="dots" class="size-4"/></a></td>
                        </tr>
                    @endforeach
                </x-table>
            @endif
        </x-card>

        {{-- Baris 5: analisa --}}
        <div class="col-span-12 min-w-0">@include('bpjs.partials.analisis')</div>

        <script type="application/json" id="bpjs-data">@json($payload)</script>
        @vite('resources/js/bpjs-dashboard.js')
    @endunless
</div>
<p class="mt-4 text-xs text-gray-500 dark:text-gray-400" data-catatan-sumber>Sumber: VClaim Monitoring BPJS, ditarik {{ $jam($lastSync) }}. Alasan pending dan status tidak layak tidak tersedia di VClaim.</p>
