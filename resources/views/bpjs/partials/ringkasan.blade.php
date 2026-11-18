@php
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pctf = fn ($n) => $n === null ? '' : number_format($n, 1, ',', '.') . '%';
    $t = $stats['tren'];
    $ds = $stats['donut_status'];
    $dj = $stats['donut_jenis'];
    $payload = ['donut_status' => $ds + ['links' => $links['status']], 'donut_jenis' => $dj + ['links' => $links['jenis']],
        'tren' => $t + ['links' => $links['tren']], 'status_keys' => array_keys(\App\Services\Dashboard\BpjsStats::STATUS)];
@endphp
<form method="GET" action="{{ route('bpjs.index') }}" class="flex flex-wrap items-end gap-2 text-sm" data-filter-ringkasan>
    <input type="hidden" name="tab" value="ringkasan">
    <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Periode (bulan tgl pulang)</span>
        <input type="month" name="periode" value="{{ $periode }}" class="field num"></label>
    <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Jenis</span>
        <select name="jenis" class="field">
            @foreach (['' => 'Semua', 'inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'] as $v => $l)
                <option value="{{ $v }}" @selected($jenisRingkasan === $v)>{{ $l }}</option>
            @endforeach
        </select></label>
    <button type="submit" class="btn-primary">Terapkan</button>
</form>

@if ($stats['kosong'])
    <x-panel><x-empty-state title="Belum ada data BPJS untuk periode ini." data-bpjs-kosong/></x-panel>
@else
    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4" data-kartu-status>
        @foreach ($stats['kartu'] as $k => $c)
            @php
                $d = $c['delta'];
                $warna = $d === 0 || $c['naik_baik'] === null ? 'text-ink-muted' : (($d > 0) === $c['naik_baik'] ? 'text-triage-green' : 'text-triage-red');
                $dasar = ['belum' => 'SEP terbit', 'kurang' => 'klaim layak'][$k] ?? 'total klaim';
            @endphp
            <a href="{{ $links['kartu'][$k] }}" data-kartu="{{ $k }}" class="block rounded-lg border border-line bg-white p-3 hover:border-biru-aktif focus:outline-none focus-visible:ring-2 focus-visible:ring-biru-aktif">
                <span class="block text-xs font-medium text-ink-muted">{{ $c['label'] }}</span>
                <span class="tabular block text-2xl font-bold text-ink" data-angka>{{ $num($c['n']) }}</span>
                <span class="block text-xs text-ink-muted">@if ($c['pct'] !== null)<span class="tabular">{{ $pctf($c['pct']) }}</span> dari {{ $dasar }}@else&nbsp;@endif</span>
                @if ($c['rp'] !== null)<span class="tabular block text-xs text-ink">{{ $rp($c['rp']) }} diajukan</span>@endif
                <span class="tabular block text-xs {{ $warna }}" data-delta>{{ $d > 0 ? "naik $d" : ($d < 0 ? 'turun ' . abs($d) : 'tetap') }} vs bulan lalu</span>
            </a>
        @endforeach
        <div class="rounded-lg border border-line bg-gray-100 p-3 text-ink-muted" data-kartu="tidak-layak" aria-disabled="true">
            <span class="block text-xs font-medium">Tidak layak / dispute</span>
            <span class="mt-2 block text-sm">Perlu impor umpan balik BPJS</span>
        </div>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach (['status' => ['Status klaim', $ds], 'jenis' => ['Jenis pelayanan', $dj]] as $id => [$judul, $d])
            @php $sum = array_sum($d['counts']); @endphp
            <x-panel class="p-4">
                <div class="flex items-center justify-between"><h2 class="font-semibold text-ink">{{ $judul }}</h2>
                    <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-{{ $id }}">Lihat tabel</button></div>
                <div class="relative mx-auto mt-2 h-64 max-w-xs">
                    <canvas id="chart-{{ $id }}" role="img" aria-label="{{ $judul }}: {{ collect($d['labels'])->map(fn ($l, $i) => $l . ' ' . $d['counts'][$i])->implode(', ') }}; total {{ $sum }} klaim"></canvas>
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center" aria-hidden="true">
                        <span class="tabular text-2xl font-bold text-ink">{{ $num($sum) }}</span><span class="text-xs text-ink-muted">klaim</span>
                    </div>
                </div>
                <table id="tabel-{{ $id }}" hidden class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1">Kelompok</th><th class="py-1 text-right">Jumlah</th><th class="py-1 text-right">%</th><th class="py-1 text-right">Diajukan</th></tr></thead>
                    <tbody>
                        @foreach ($d['labels'] as $i => $l)
                            <tr><td class="py-1">{{ $l }}</td><td class="tabular py-1 text-right">{{ $num($d['counts'][$i]) }}</td>
                                <td class="tabular py-1 text-right">{{ $sum ? $pctf($d['counts'][$i] * 100 / $sum) : '—' }}</td><td class="tabular py-1 text-right">{{ $rp($d['rp'][$i]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </x-panel>
        @endforeach
    </div>

    <x-panel class="p-4">
        <div class="flex items-center justify-between"><h2 class="font-semibold text-ink">Tren status klaim 12 bulan</h2>
            <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-tren">Lihat tabel</button></div>
        <div class="relative mt-2 h-72"><canvas id="chart-tren" role="img" aria-label="Tren klaim {{ $t['label'][0] }} sampai {{ $t['label'][11] }}, batang bertumpuk per status dan garis persen pending. {{ $t['label'][11] }}: proses {{ $t['proses'][11] }}, pending {{ $t['pending'][11] }}, klaim {{ $t['klaim'][11] }}."></canvas></div>
        <div class="overflow-x-auto"><table id="tabel-tren" hidden class="mt-3 w-full text-sm">
            <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1">Bulan</th><th class="py-1 text-right">Proses</th><th class="py-1 text-right">Pending</th><th class="py-1 text-right">Klaim</th><th class="py-1 text-right">% pending</th></tr></thead>
            <tbody>
                @foreach ($t['label'] as $i => $l)
                    <tr><td class="py-1">{{ $l }}@if ($t['go_live'] === $i) <span class="text-xs text-ink-muted">(CERMAT mulai)</span>@endif</td>
                        <td class="tabular py-1 text-right">{{ $t['proses'][$i] }}</td><td class="tabular py-1 text-right">{{ $t['pending'][$i] }}</td>
                        <td class="tabular py-1 text-right">{{ $t['klaim'][$i] }}</td><td class="tabular py-1 text-right">{{ $pctf($t['pct_pending'][$i]) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    </x-panel>

    <x-panel class="p-4">
        <div class="flex items-center justify-between"><h2 class="font-semibold text-ink">Nilai klaim layak per bulan</h2>
            <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-nilai">Lihat tabel</button></div>
        <div class="relative mt-2 h-72"><canvas id="chart-nilai" role="img" aria-label="Nilai klaim layak 12 bulan, diajukan dibanding disetujui. {{ $t['label'][11] }}: diajukan {{ $rp($t['diajukan'][11]) }}, disetujui {{ $rp($t['disetujui'][11]) }}."></canvas></div>
        <div class="overflow-x-auto"><table id="tabel-nilai" hidden class="mt-3 w-full text-sm">
            <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1">Bulan</th><th class="py-1 text-right">Diajukan</th><th class="py-1 text-right">Disetujui</th></tr></thead>
            <tbody>
                @foreach ($t['label'] as $i => $l)
                    <tr><td class="py-1">{{ $l }}</td><td class="tabular py-1 text-right">{{ $rp($t['diajukan'][$i]) }}</td><td class="tabular py-1 text-right">{{ $rp($t['disetujui'][$i]) }}</td></tr>
                @endforeach
            </tbody>
        </table></div>
    </x-panel>

    <x-panel class="p-4" data-funnel>
        <h2 class="font-semibold text-ink">Alur SEP terbit ke klaim layak</h2>
        @php $maks = max(1, ...array_column($stats['funnel'], 'n')); @endphp
        <ol class="mt-3 space-y-2">
            @foreach ($stats['funnel'] as $i => $f)
                <li>
                    <div class="flex justify-between gap-2 text-sm"><span>{{ $f['label'] }}</span>
                        <span class="tabular">{{ $num($f['n']) }}@if ($i > 0)<span class="text-xs text-ink-muted"> · {{ $stats['funnel'][$i - 1]['n'] ? $pctf($f['n'] * 100 / $stats['funnel'][$i - 1]['n']) : '—' }} dari tahap sebelumnya</span>@endif</span></div>
                    <div class="mt-1 h-3 rounded bg-gray-100"><div class="h-3 rounded {{ ['bg-ink-muted', 'bg-triage-amber', 'bg-triage-green'][$i] }}" style="width: {{ round($f['n'] * 100 / $maks, 1) }}%"></div></div>
                </li>
            @endforeach
        </ol>
    </x-panel>

    <script type="application/json" id="bpjs-data">@json($payload)</script>
    @vite('resources/js/bpjs-dashboard.js')
@endif
<p class="text-xs text-ink-muted" data-catatan-sumber>Sumber: VClaim Monitoring BPJS, ditarik {{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->format('d/m/Y H:i') : 'belum pernah' }}. Alasan pending dan status tidak layak tidak tersedia di VClaim.</p>
