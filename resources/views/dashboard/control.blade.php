<x-app-layout title="Dashboard">
@php
    $v = $s['vonis'];
    $bagi = max(1, $v['siap'] + $v['tinjau'] + $v['blokir']);
    $warna = ['siap' => 'bg-sesuai', 'tinjau' => 'bg-waspada', 'blokir' => 'bg-kritis'];
    $label = ['siap' => 'Siap', 'tinjau' => 'Tinjau', 'blokir' => 'Blokir'];
    $maxRubrik = max(1, collect($s['rubrik'])->max('n'));
    $skor = $s['skor'] ?? 0;
@endphp

<div class="space-y-6">
    {{-- 1. Kepala --}}
    <section class="panel px-5 py-4" aria-labelledby="judul-dashboard">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 id="judul-dashboard" class="text-xl font-bold text-arang">Pusat Kendali Klaim</h1>
                <p class="text-sm text-abu">{{ config('casemix.hospital_name') }} · <span data-total>{{ $s['total'] }}</span> folder diperiksa</p>
            </div>
            <nav class="flex flex-wrap gap-1" aria-label="Periode">
                @foreach (\App\Services\Dashboard\DashboardStats::PERIODS as $k => $l)
                    <a href="{{ route('control.index', ['periode' => $k]) }}" @if ($period === $k) aria-current="page" @endif
                       @class(['rounded-full px-3 py-1 text-sm', 'bg-biru text-white' => $period === $k, 'bg-abu-muda text-arang hover:bg-biru-muda' => $period !== $k])>{{ $l }}</a>
                @endforeach
            </nav>
        </div>
        <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-abu-muda" role="img" aria-label="Siap {{ $v['siap'] }}, Tinjau {{ $v['tinjau'] }}, Blokir {{ $v['blokir'] }}">
            @foreach ($label as $k => $l)
                @if ($v[$k])<div class="{{ $warna[$k] }}" style="width: {{ round($v[$k] / $bagi * 100, 1) }}%"></div>@endif
            @endforeach
        </div>
        <ul class="mt-2 flex flex-wrap gap-4 text-sm">
            @foreach ($label as $k => $l)
                <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-full {{ $warna[$k] }}" aria-hidden="true"></span>{{ $l }} <strong class="num" data-vonis="{{ $k }}">{{ $v[$k] }}</strong></li>
            @endforeach
        </ul>
        {{-- 2. Status sinkron --}}
        <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-abu">
            <span>Sinkron FTP terakhir {{ $s['sinkron'] ? \Illuminate\Support\Carbon::parse($s['sinkron'])->format('H:i') : '—' }}</span>
            <span class="text-gray-400">Status klaim BPJS (VClaim): belum tersambung</span>
        </p>
    </section>

    {{-- 3. Kartu --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <section class="panel flex items-center gap-4 px-5 py-4">
            <div class="relative grid size-20 shrink-0 place-items-center rounded-full" style="background: conic-gradient(var(--color-sesuai) {{ $skor * 3.6 }}deg, var(--color-abu-muda) 0)" role="img" aria-label="Skor klaim bersih {{ $s['skor'] ?? '—' }} persen">
                <span class="grid size-14 place-items-center rounded-full bg-white text-lg font-bold text-arang" data-skor>{{ $s['skor'] !== null ? $s['skor'] . '%' : '—' }}</span>
            </div>
            <div><p class="text-sm font-medium text-arang">Skor klaim bersih</p><p class="text-xs text-abu"><span data-kartu="siap">{{ $v['siap'] }}</span> siap dari {{ $s['dianalisa'] }} dianalisa</p></div>
        </section>
        @foreach ([['tinjau', 'Tinjau', $v['tinjau'], 'text-waspada'], ['blokir', 'Blokir', $v['blokir'], 'text-kritis'], ['belum', 'Belum dianalisa', $v['belum'], 'text-arang'], ['dpjp', 'Saran menunggu DPJP', $s['menunggu_dpjp'], 'text-biru-aktif']] as [$k, $l, $n, $c])
            <section class="panel px-5 py-4"><p class="text-sm text-abu">{{ $l }}</p><p class="mt-1 text-3xl font-bold {{ $c }}" data-kartu="{{ $k }}">{{ $n }}</p></section>
        @endforeach
        <section class="panel px-5 py-4"><p class="text-sm text-abu">Biaya AI bulan ini</p><p class="mt-1 text-3xl font-bold text-arang" data-kartu="biaya">US$ {{ number_format($s['biaya_ai'], 2) }}</p></section>
        <section class="panel border-dashed bg-abu-muda px-5 py-4 text-gray-500"><p class="text-sm">Nilai klaim &amp; status BPJS — tersedia setelah VClaim tersambung</p></section>
    </div>

    {{-- 4. Butir rubrik paling sering kurang --}}
    <section class="panel" aria-labelledby="judul-rubrik">
        <div class="panel-head"><h2 id="judul-rubrik" class="panel-title">Butir rubrik paling sering kurang</h2></div>
        <ul class="space-y-2 px-5 py-4 text-sm">
            @foreach ($s['rubrik'] as $r)
                <li class="grid grid-cols-[minmax(0,14rem)_1fr_2.5rem] items-center gap-3" data-rubrik="{{ $r['kode'] }}">
                    <span class="truncate text-arang">{{ $r['label'] }}</span>
                    <span class="h-2.5 rounded-full bg-abu-muda"><span class="block h-full rounded-full bg-biru" style="width: {{ round($r['n'] / $maxRubrik * 100, 1) }}%"></span></span>
                    <span class="num text-right">{{ $r['n'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- 5. Kategori ICD-10 3 digit dari saran kode --}}
    @if ($s['kode_aktif'])
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ([['kurang_klaim', 'Kemungkinan klaim kurang', 'Saran diagnosis sekunder berbukti yang belum tercantum di resume.'], ['kurang_bukti', 'Diagnosis kurang bukti', 'Folder dengan butir bukti diagnosis kurang, menurut kode diagnosis utama.']] as [$k, $judul, $ket])
                @php $d = $s[$k]; $mx = max(1, collect($d['items'])->max('n')); @endphp
                <section class="panel" data-panel="{{ $k }}">
                    <div class="panel-head"><div><h2 class="panel-title">{{ $judul }}</h2><p class="text-xs text-abu">{{ $ket }}</p></div></div>
                    <div class="px-5 py-4 text-sm">
                        @if (! $d['items'])
                            <p class="text-abu">Belum ada data.</p>
                        @else
                            <ul class="space-y-2">
                                @foreach ($d['items'] as $i)
                                    <li class="grid grid-cols-[3.5rem_1fr_2.5rem] items-center gap-3">
                                        <strong class="num">{{ $i['kategori'] }}</strong>
                                        <span class="h-2.5 rounded-full bg-abu-muda"><span class="block h-full rounded-full bg-waspada" style="width: {{ round($i['n'] / $mx * 100, 1) }}%"></span></span>
                                        <span class="num text-right">{{ $i['n'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($d['lain'])<p class="mt-2 text-xs text-abu">{{ $d['lain'] }} kategori lain</p>@endif
                        @endif
                    </div>
                </section>
            @endforeach
        </div>
        <p class="text-xs text-abu">Saran kode bukan keputusan koding; konfirmasi ke DPJP. Koding final oleh koder berdasarkan resume DPJP.</p>
    @endif
</div>
</x-app-layout>
