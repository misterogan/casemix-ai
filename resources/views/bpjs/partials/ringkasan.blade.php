@php
    use App\Services\Dashboard\BpjsStats;
    $rp = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $pctf = fn ($n) => $n === null ? '' : number_format($n, 1, ',', '.') . '%';
    $jam = fn ($w) => $w ? \Illuminate\Support\Carbon::parse($w)->format('d/m/Y H:i') : 'belum pernah';
    $t = $stats['tren'];
    $ds = $stats['donut_status'];
    $dj = $stats['donut_jenis'];
    $payload = ['donut_status' => $ds + ['links' => $links['status']], 'donut_jenis' => $dj + ['links' => $links['jenis']],
        'tren' => $t + ['links' => $links['tren']], 'status_keys' => array_keys(BpjsStats::STATUS), 'status_label' => BpjsStats::STATUS_LABEL,
        'los' => $stats['analisis']['mutu']['los'] ?? null, 'readmisi' => $stats['analisis']['mutu']['readmisi']['tren'] ?? null];
    $panel = 'rounded-[10px] border border-line bg-white p-5';
    $ket = 'mt-1 text-xs text-ink-muted';
    $warnaStatus = ['proses' => 'bg-triage-amber', 'pending' => 'bg-triage-red', 'tidak_layak' => 'bg-[#7F1D1D]', 'dispute' => 'bg-[#6B21A8]',
        'klaim' => 'bg-triage-green', 'lainnya' => 'bg-[#9AA0A6]'];
@endphp

{{-- Hero --}}
<section class="rounded-[10px] bg-brand-soft p-6" data-hero>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <h2 class="text-xl font-semibold text-ink">Pusat kendali klaim BPJS</h2>
        <form method="GET" action="{{ route('bpjs.index') }}" class="flex flex-wrap items-end gap-2 text-sm" data-filter-ringkasan>
            <input type="hidden" name="tab" value="ringkasan">
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Periode (bulan tgl pulang)</span>
                <input type="month" name="periode" value="{{ $periode }}" class="field num rounded-[6px]"></label>
            <label class="flex flex-col gap-1"><span class="text-xs text-ink-muted">Jenis</span>
                <select name="jenis" class="field rounded-[6px]">
                    @foreach (['' => 'Semua', 'inap' => 'Rawat inap', 'jalan' => 'Rawat jalan'] as $v => $l)
                        <option value="{{ $v }}" @selected($jenisRingkasan === $v)>{{ $l }}</option>
                    @endforeach
                </select></label>
            <button type="submit" class="btn-primary rounded-[6px]">Terapkan</button>
        </form>
    </div>

    @if (! $stats['kosong'])
        <div class="mt-5 grid gap-6 lg:grid-cols-[1fr_minmax(0,22rem)] lg:items-end">
            <div>
                <p class="tabular max-w-3xl text-base leading-relaxed text-ink" data-kalimat>@foreach ($stats['kalimat'] as $p)@if (isset($p['b']))<strong class="font-semibold" @if (isset($p['title'])) title="{{ $p['title'] }}" @endif>{{ $p['b'] }}</strong>@else{{ $p['t'] }}@endif @endforeach</p>
                <div class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span class="rounded-[6px] bg-triage-green-soft px-2 py-0.5 text-triage-green" data-chip-resmi>Nilai resmi VClaim</span>
                    <span class="rounded-[6px] bg-white px-2 py-0.5 text-ink-muted" data-chip-ditarik>Ditarik {{ $jam($lastSync) }}</span>
                </div>
            </div>
            @php $tot = max(1, $stats['total_klaim']); @endphp
            <div data-bar-status>
                <div class="flex h-3 overflow-hidden rounded-[6px] bg-white" role="img"
                     aria-label="Komposisi status: {{ collect($ds['keys'])->map(fn ($k, $i) => $ds['labels'][$i] . ' ' . $ds['counts'][$i])->implode(', ') }}">
                    @foreach ($ds['keys'] as $i => $k)
                        @if ($ds['counts'][$i] > 0)<div class="{{ $warnaStatus[$k] }}" style="width: {{ round($ds['counts'][$i] * 100 / $tot, 2) }}%"></div>@endif
                    @endforeach
                </div>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-muted">
                    @foreach ($ds['keys'] as $i => $k)
                        @continue($k === 'lainnya' && ! $ds['counts'][$i])
                        <li class="flex items-center gap-1.5" data-legenda="{{ $k }}"><span class="inline-block h-2 w-2 rounded-full {{ $warnaStatus[$k] }}"></span>
                            {{ $ds['labels'][$i] }} <span class="tabular font-medium text-ink">{{ $num($ds['counts'][$i]) }}</span></li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</section>

{{-- Status sinkron --}}
<div class="grid gap-3 rounded-[10px] border border-line bg-white px-5 py-3 text-sm md:grid-cols-2" data-sinkron>
    @foreach (['klaim' => 'Data klaim', 'kunjungan' => 'Data kunjungan'] as $j => $judul)
        @php $s = $sinkron[$j] ?? null; @endphp
        <p class="flex items-start gap-2" data-sinkron-{{ $j }}>
            <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full {{ $s === null ? 'bg-ink-muted' : ($s['ok'] ? 'bg-triage-green' : 'bg-triage-red') }}" aria-hidden="true"></span>
            <span><span class="font-medium text-ink">{{ $judul }}</span>
                <span class="tabular text-ink-muted"> · terakhir {{ $jam($s['waktu'] ?? null) }}@if ($s)
                    · @if ($j === 'klaim'){{ $num($s['dicek']) }} SEP dicek, {{ $num($s['berubah']) }} status berubah @else{{ $num($s['baru']) }} SEP baru @endif
                @endif</span>
                @if ($s && ! $s['ok'])<span class="block text-xs text-triage-red" data-sinkron-gagal>Tarikan terakhir gagal{{ $s['error'] ? ': ' . $s['error'] : '' }}</span>@endif
            </span>
        </p>
    @endforeach
</div>

@if ($stats['kosong'])
    <x-panel><x-empty-state title="Belum ada data BPJS untuk periode ini." data-bpjs-kosong/></x-panel>
@else
    {{-- Kartu status --}}
    @php
        $tema = ['sep' => ['text-brand', 'bg-brand-soft'], 'proses' => ['text-triage-amber', 'bg-triage-amber-soft'],
            'pending' => ['text-triage-red', 'bg-triage-red-soft'], 'klaim' => ['text-triage-green', 'bg-triage-green-soft'],
            'belum' => ['text-ink', 'bg-gray-100'], 'kurang' => ['text-triage-amber', 'bg-triage-amber-soft'],
            'tertahan' => ['text-triage-red', 'bg-triage-red-soft'], 'tolak' => ['text-[#7F1D1D]', 'bg-[#FBE4E4]']];
        $ikon = ['sep' => 'M4 5h16v14H4zM8 9h8M8 13h5', 'proses' => 'M12 6v6l4 2M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
            'pending' => 'M10 9v6M14 9v6M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'klaim' => 'M5 12l5 5L19 7',
            'belum' => 'M12 8v4M12 16h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z', 'kurang' => 'M6 12h12',
            'tertahan' => 'M6 10V8a6 6 0 1 1 12 0v2M5 10h14v10H5z', 'tolak' => 'M6 6l12 12M18 6L6 18'];
    @endphp
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4" data-kartu-status>
        @foreach ($stats['kartu'] as $k => $c)
            @php
                $d = $c['delta'];
                $uang = ! empty($c['rupiah']);
                $warna = $d == 0 || $c['naik_baik'] === null ? 'text-ink-muted' : (($d > 0) === $c['naik_baik'] ? 'text-triage-green' : 'text-triage-red');
                $dasar = ['belum' => 'SEP terbit', 'kurang' => 'klaim layak'][$k] ?? 'total klaim';
                $fmtD = fn ($x) => $uang ? BpjsStats::rupiahRingkas(abs($x)) : $num(abs($x));
            @endphp
            <a href="{{ $links['kartu'][$k] }}" data-kartu="{{ $k }}" class="block rounded-[10px] border border-line bg-white p-4 hover:border-biru-aktif focus:outline-none focus-visible:ring-2 focus-visible:ring-biru-aktif">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-[6px] {{ $tema[$k][1] }} {{ $tema[$k][0] }}" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $ikon[$k] }}"/></svg>
                </span>
                @if ($uang)
                    <span class="tabular mt-3 block text-2xl font-semibold {{ $tema[$k][0] }}" data-angka title="{{ BpjsStats::rupiahPenuh($c['n']) }}">{{ BpjsStats::rupiahRingkas($c['n']) }}</span>
                @else
                    <span class="tabular mt-3 block text-2xl font-semibold {{ $tema[$k][0] }}" data-angka>{{ $num($c['n']) }}</span>
                @endif
                <span class="mt-1 block text-sm text-ink">{{ $c['label'] }}</span>
                @if ($c['pct'] !== null)<span class="block text-xs text-ink-muted"><span class="tabular">{{ $pctf($c['pct']) }}</span> dari {{ $dasar }}</span>@endif
                @if ($c['rp'] !== null)<span class="tabular block text-xs text-ink-muted" title="{{ BpjsStats::rupiahPenuh($c['rp']) }}">{{ BpjsStats::rupiahRingkas($c['rp']) }} diajukan</span>@endif
                @if ($k === 'belum')<span class="mt-1 block text-xs text-ink-muted" data-belum-catatan>SEP sudah terbit (pelayanan dijamin BPJS), tetapi tagihan klaimnya belum tercatat di BPJS. Bisa belum diajukan dari E-Klaim atau belum diproses.</span>
                    <span class="mt-1 block text-xs text-ink-muted" data-belum-umur>@foreach ($stats['aksi']['belum_umur'] ?? [] as $u => $nu)<span class="tabular">{{ $num($nu) }}</span> {{ $u }}@if (! $loop->last) · @endif @endforeach</span>@endif
                @if ($k === 'tolak')<span class="mt-1 block text-xs text-ink-muted" data-tolak-catatan>Alasan tidak tersedia di VClaim. Lihat umpan balik verifikator.</span>@endif
                <span class="tabular mt-1 block text-xs {{ $warna }}" data-delta>{{ $d > 0 ? '+' . $fmtD($d) : ($d < 0 ? '−' . $fmtD($d) : 'sama') }} vs bulan lalu</span>
            </a>
        @endforeach
    </div>

    {{-- Panel bertindak --}}
    @php
        $aksi = [
            'pending14' => ['Pending lebih dari 14 hari', 'klaim tertahan dan berisiko melewati batas tanggapan.',
                'periksa umpan balik verifikator, lengkapi berkas, ajukan ulang.', 'bg-triage-red-soft', 'text-triage-red'],
            'belum7' => ['Sudah pulang, tagihan belum tercatat di BPJS (>7 hari sejak pulang)', 'Layanan sudah diberikan, tetapi klaimnya belum sampai ke BPJS.',
                'Cek di E-Klaim apakah sudah diajukan; yang lebih dari 30 hari sejak pulang didahulukan.', 'bg-triage-amber-soft', 'text-triage-amber'],
            'tidak_layak' => ['Klaim tidak layak', 'BPJS menolak klaim ini.',
                'telusuri alasan dari verifikator, nilai apakah layak diajukan dispute.', 'bg-triage-red-soft', 'text-[#7F1D1D]'],
        ];
    @endphp
    <div class="grid gap-4 md:grid-cols-2" data-panel-aksi>
        @foreach ($aksi as $k => [$judul, $arti, $tindak, $bg, $fg])
            @php $n = $stats['aksi'][$k]; @endphp
            @continue($k === 'tidak_layak' && $n === 0)
            @if ($n === 0)
                <div class="rounded-[10px] bg-triage-green-soft p-5" data-aksi="{{ $k }}" data-aksi-aman>
                    <span class="tabular block text-3xl font-semibold text-triage-green">0</span>
                    <span class="mt-1 block font-medium text-ink">{{ $judul }}</span>
                    <p class="mt-2 text-sm text-triage-green">Tidak ada yang perlu dikejar</p>
                </div>
            @else
                <div class="rounded-[10px] {{ $bg }} p-5" data-aksi="{{ $k }}">
                    <span class="tabular block text-3xl font-semibold {{ $fg }}" data-aksi-n>{{ $num($n) }}</span>
                    <span class="mt-1 block font-medium text-ink">{{ $judul }}</span>
                    @if ($k === 'pending14' && $stats['aksi']['pending14_rp'] > 0)
                        <span class="tabular block text-xs text-ink-muted" title="{{ BpjsStats::rupiahPenuh($stats['aksi']['pending14_rp']) }}">{{ BpjsStats::rupiahRingkas($stats['aksi']['pending14_rp']) }} nilai tertahan</span>
                    @endif
                    @if ($k === 'tidak_layak')
                        <span class="tabular block text-xs text-ink-muted" title="{{ BpjsStats::rupiahPenuh($stats['aksi']['tidak_layak_rp']) }}" data-aksi-rp>{{ BpjsStats::rupiahRingkas($stats['aksi']['tidak_layak_rp']) }} diajukan</span>
                    @endif
                    <p class="mt-3 text-sm text-ink"><span class="font-medium">Artinya:</span> {{ $arti }}</p>
                    <p class="mt-1 text-sm text-ink"><span class="font-medium">Tindakan:</span> {{ $tindak }}</p>
                    <a href="{{ $links['aksi'][$k] }}" class="mt-3 inline-block text-sm text-biru-aktif underline" data-aksi-link>Lihat daftar</a>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Gambaran --}}
    <section class="space-y-4" data-gambaran>
        <h2 class="pt-2 text-lg font-semibold text-ink">Gambaran</h2>
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @foreach (['status' => ['Status klaim', $ds, 'Porsi klaim per status pada periode ini. Klik irisan untuk membuka daftarnya.'],
                'jenis' => ['Jenis pelayanan', $dj, 'Porsi klaim rawat inap dibanding rawat jalan. Klik irisan untuk membuka daftarnya.']] as $id => [$judul, $d, $baca])
                @php $sum = array_sum($d['counts']); @endphp
                <div class="{{ $panel }}">
                    <div class="flex items-center justify-between"><h3 class="font-semibold text-ink">{{ $judul }}</h3>
                        <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-{{ $id }}">Lihat tabel</button></div>
                    <p class="{{ $ket }}">{{ $baca }}</p>
                    <div class="relative mx-auto mt-3 h-64 max-w-xs">
                        <canvas id="chart-{{ $id }}" role="img" aria-label="{{ $judul }}: {{ collect($d['labels'])->map(fn ($l, $i) => $l . ' ' . $d['counts'][$i])->implode(', ') }}; total {{ $sum }} klaim"></canvas>
                        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center" aria-hidden="true">
                            <span class="tabular text-2xl font-semibold text-ink">{{ $num($sum) }}</span><span class="text-xs text-ink-muted">klaim</span>
                        </div>
                    </div>
                    <table id="tabel-{{ $id }}" hidden class="mt-3 w-full text-sm">
                        <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1 font-medium">Kelompok</th><th class="py-1 text-right font-medium">Jumlah</th><th class="py-1 text-right font-medium">%</th><th class="py-1 text-right font-medium">Diajukan</th></tr></thead>
                        <tbody>
                            @foreach ($d['labels'] as $i => $l)
                                <tr><td class="py-1">{{ $l }}</td><td class="tabular py-1 text-right">{{ $num($d['counts'][$i]) }}</td>
                                    <td class="tabular py-1 text-right">{{ $sum ? $pctf($d['counts'][$i] * 100 / $sum) : '—' }}</td><td class="tabular py-1 text-right">{{ $rp($d['rp'][$i]) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach

            <div class="{{ $panel }}">
                <div class="flex items-center justify-between"><h3 class="font-semibold text-ink">Tren status klaim 12 bulan</h3>
                    <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-tren">Lihat tabel</button></div>
                <p class="{{ $ket }}">Batang bertumpuk menunjukkan jumlah per status; garis putus-putus adalah persen pending (sumbu kanan).</p>
                <div class="relative mt-3 h-72"><canvas id="chart-tren" role="img" aria-label="Tren klaim {{ $t['label'][0] }} sampai {{ $t['label'][11] }}, batang bertumpuk per status dan garis persen pending. {{ $t['label'][11] }}: proses {{ $t['proses'][11] }}, pending {{ $t['pending'][11] }}, tidak layak {{ $t['tidak_layak'][11] }}, dispute {{ $t['dispute'][11] }}, klaim {{ $t['klaim'][11] }}."></canvas></div>
                <div class="overflow-x-auto"><table id="tabel-tren" hidden class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1 font-medium">Bulan</th><th class="py-1 text-right font-medium">Proses</th><th class="py-1 text-right font-medium">Pending</th><th class="py-1 text-right font-medium">Tidak layak</th><th class="py-1 text-right font-medium">Dispute</th><th class="py-1 text-right font-medium">Klaim</th><th class="py-1 text-right font-medium">% pending</th></tr></thead>
                    <tbody>
                        @foreach ($t['label'] as $i => $l)
                            <tr><td class="py-1">{{ $l }}@if ($t['go_live'] === $i) <span class="text-xs text-ink-muted">(CERMAT mulai)</span>@endif</td>
                                <td class="tabular py-1 text-right">{{ $t['proses'][$i] }}</td><td class="tabular py-1 text-right">{{ $t['pending'][$i] }}</td><td class="tabular py-1 text-right">{{ $t['tidak_layak'][$i] }}</td><td class="tabular py-1 text-right">{{ $t['dispute'][$i] }}</td>
                                <td class="tabular py-1 text-right">{{ $t['klaim'][$i] }}</td><td class="tabular py-1 text-right">{{ $pctf($t['pct_pending'][$i]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>

            <div class="{{ $panel }}">
                <div class="flex items-center justify-between"><h3 class="font-semibold text-ink">Nilai klaim layak per bulan</h3>
                    <button type="button" class="text-xs text-biru-aktif underline" data-lihat-tabel aria-expanded="false" aria-controls="tabel-nilai">Lihat tabel</button></div>
                <p class="{{ $ket }}">Bandingkan batang diajukan dan disetujui; jarak keduanya adalah selisih yang tidak dibayar.</p>
                <div class="relative mt-3 h-72"><canvas id="chart-nilai" role="img" aria-label="Nilai klaim layak 12 bulan, diajukan dibanding disetujui. {{ $t['label'][11] }}: diajukan {{ $rp($t['diajukan'][11]) }}, disetujui {{ $rp($t['disetujui'][11]) }}."></canvas></div>
                <div class="overflow-x-auto"><table id="tabel-nilai" hidden class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1 font-medium">Bulan</th><th class="py-1 text-right font-medium">Diajukan</th><th class="py-1 text-right font-medium">Disetujui</th></tr></thead>
                    <tbody>
                        @foreach ($t['label'] as $i => $l)
                            <tr><td class="py-1">{{ $l }}</td><td class="tabular py-1 text-right">{{ $rp($t['diajukan'][$i]) }}</td><td class="tabular py-1 text-right">{{ $rp($t['disetujui'][$i]) }}</td></tr>
                        @endforeach
                    </tbody>
                </table></div>
            </div>

            <div class="{{ $panel }}" data-funnel>
                <h3 class="font-semibold text-ink">Alur SEP terbit ke klaim layak</h3>
                <p class="{{ $ket }}">Tiap baris menunjukkan berapa yang lolos dari tahap sebelumnya; penurunan besar menandai tahap yang perlu dicek.</p>
                @php $maks = max(1, ...array_column($stats['funnel'], 'n')); @endphp
                <ol class="mt-4 space-y-3">
                    @foreach ($stats['funnel'] as $i => $f)
                        <li>
                            <div class="flex justify-between gap-2 text-sm"><span>{{ $f['label'] }}</span>
                                <span class="tabular">{{ $num($f['n']) }}@if ($i > 0)<span class="text-xs text-ink-muted"> · {{ $stats['funnel'][$i - 1]['n'] ? $pctf($f['n'] * 100 / $stats['funnel'][$i - 1]['n']) : '—' }} dari tahap sebelumnya</span>@endif</span></div>
                            <div class="mt-1 h-3 rounded-[6px] bg-gray-100"><div class="h-3 rounded-[6px] {{ ['bg-ink-muted', 'bg-triage-amber', 'bg-triage-green'][$i] }}" style="width: {{ round($f['n'] * 100 / $maks, 1) }}%"></div></div>
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    </section>

    @include('bpjs.partials.analisis')

    <script type="application/json" id="bpjs-data">@json($payload)</script>
    @vite('resources/js/bpjs-dashboard.js')
@endif
<p class="text-xs text-ink-muted" data-catatan-sumber>Sumber: VClaim Monitoring BPJS, ditarik {{ $jam($lastSync) }}. Alasan pending dan status tidak layak tidak tersedia di VClaim.</p>
