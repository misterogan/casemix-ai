<x-app-layout title="Dashboard">
@php
    $v = $s['vonis'];
    $bagi = max(1, $v['siap'] + $v['tinjau'] + $v['blokir']);
    $warna = ['siap' => 'bg-success-500', 'tinjau' => 'bg-warning-500', 'blokir' => 'bg-error-500'];
    $label = ['siap' => 'Siap', 'tinjau' => 'Tinjau', 'blokir' => 'Blokir'];
    $maxRubrik = max(1, collect($s['rubrik'])->max('n'));
    $skor = $s['skor'] ?? 0;
    $angka = fn (string $k, $n) => new \Illuminate\Support\HtmlString('<span data-kartu="' . e($k) . '">' . e($n) . '</span>');
    $rp = fn ($n) => \App\Services\Dashboard\BpjsStats::rupiahRingkas((float) $n);
    $rpPenuh = fn ($n) => \App\Services\Dashboard\BpjsStats::rupiahPenuh((float) $n);
    $selisihHtml = fn ($n) => new \Illuminate\Support\HtmlString('<span title="' . e($rpPenuh($n)) . '">' . e($rp($n)) . '</span>');
    $state = [
        'belum' => ['Belum dianalisa', 'gray', 'clock', 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'],
        'proses' => ['Sedang dianalisa', 'info', 'clock', 'bg-info-50 text-info-600 dark:bg-info-500/10'],
        'gagal' => ['Gagal', 'error', 'x', 'bg-error-50 text-error-600 dark:bg-error-500/10'],
        'berubah' => ['Berkas berubah', 'warning', 'alert', 'bg-warning-50 text-warning-600 dark:bg-warning-500/10'],
        'temuan' => ['Belum siap', 'warning', 'alert', 'bg-warning-50 text-warning-600 dark:bg-warning-500/10'],
        'lengkap' => ['Siap diajukan', 'success', 'check', 'bg-success-50 text-success-600 dark:bg-success-500/10'],
    ];
    $terbaru = \App\Models\FtpFolder::query()->whereNull('missing_at')->orderByDesc('files_modified_at')->orderByDesc('id')->limit(8)->get();
@endphp

<div class="grid grid-cols-12 gap-4 md:gap-6">
    {{-- Kepala --}}
    <section class="col-span-12 rounded-2xl border border-gray-200 bg-white p-5 sm:p-6 dark:border-gray-800 dark:bg-white/[.03]" aria-labelledby="judul-dashboard">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <h1 id="judul-dashboard" class="text-xl font-semibold text-gray-800 dark:text-white/90">Pusat Kendali Klaim</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ config('casemix.hospital_name') }} · <span class="num" data-total>{{ $s['total'] }}</span> folder diperiksa</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <nav class="inline-flex rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-gray-800" aria-label="Periode">
                    @foreach (\App\Services\Dashboard\DashboardStats::PERIODS as $k => $l)
                        <a href="{{ route('control.index', ['periode' => $k, 'sumber' => request('sumber')]) }}" @if ($period === $k) aria-current="page" @endif
                           @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white text-gray-800 shadow-xs dark:bg-gray-900 dark:text-white/90' => $period === $k, 'text-gray-500 hover:text-gray-800' => $period !== $k])>{{ $l }}</a>
                    @endforeach
                </nav>
                <nav class="inline-flex items-center rounded-lg bg-gray-100 p-0.5 text-sm dark:bg-gray-800" aria-label="Sumber">
                    <span class="px-2 text-gray-500">Sumber</span>
                    @foreach (['' => 'Semua', 'ftp' => 'FTP', 'manual' => 'Manual'] as $k => $l)
                        <a href="{{ route('control.index', array_filter(['periode' => $period, 'sumber' => $k])) }}" @if (request('sumber', '') === $k) aria-current="page" @endif
                           @class(['rounded-md px-3 py-1.5 font-medium', 'bg-white text-gray-800 shadow-xs dark:bg-gray-900 dark:text-white/90' => request('sumber', '') === $k, 'text-gray-500 hover:text-gray-800' => request('sumber', '') !== $k])>{{ $l }}</a>
                    @endforeach
                </nav>
            </div>
        </div>
        <div class="mt-5 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800" role="img" aria-label="Siap {{ $v['siap'] }}, Tinjau {{ $v['tinjau'] }}, Blokir {{ $v['blokir'] }}">
            @foreach ($label as $k => $l)
                @if ($v[$k])<div class="{{ $warna[$k] }}" style="width: {{ round($v[$k] / $bagi * 100, 1) }}%"></div>@endif
            @endforeach
        </div>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <ul class="flex flex-wrap gap-4 text-sm text-gray-600 dark:text-gray-300">
                @foreach ($label as $k => $l)
                    <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-full {{ $warna[$k] }}" aria-hidden="true"></span>{{ $l }} <strong class="num text-gray-800 dark:text-white/90" data-vonis="{{ $k }}">{{ $v[$k] }}</strong></li>
                @endforeach
            </ul>
            <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                <span>Sinkron FTP terakhir {{ $s['sinkron'] ? \Illuminate\Support\Carbon::parse($s['sinkron'])->format('H:i') : '—' }}</span>
                @if ($s['vclaim'])
                    <span data-vclaim-terakhir>VClaim terakhir {{ $s['vclaim']['terakhir'] ? \Illuminate\Support\Carbon::parse($s['vclaim']['terakhir'])->format('d/m H:i') : '—' }}</span>
                @else
                    <span class="text-gray-400">Status klaim BPJS (VClaim): belum tersambung</span>
                @endif
            </p>
        </div>
    </section>

    {{-- Kartu stat --}}
    <div class="col-span-12 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
        <section class="flex items-center gap-4 rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[.03]">
            <div class="relative grid size-20 shrink-0 place-items-center rounded-full" style="background: conic-gradient(var(--color-success-500) {{ $skor * 3.6 }}deg, var(--color-gray-100) 0)" role="img" aria-label="Skor klaim bersih {{ $s['skor'] ?? '—' }} persen">
                <span class="num grid size-14 place-items-center rounded-full bg-white text-lg font-semibold text-gray-800 dark:bg-gray-900 dark:text-white/90" data-skor>{{ $s['skor'] !== null ? $s['skor'] . '%' : '—' }}</span>
            </div>
            <div><p class="text-sm text-gray-500 dark:text-gray-400">Skor klaim bersih</p><p class="mt-1 text-xs text-gray-500"><span class="num font-medium text-gray-800 dark:text-white/90" data-kartu="siap">{{ $v['siap'] }}</span> siap dari <span class="num">{{ $s['dianalisa'] }}</span> dianalisa</p></div>
        </section>
        <x-stat-card label="Tinjau" :value="$angka('tinjau', $v['tinjau'])" icon="alert" tone="warning"/>
        <x-stat-card label="Blokir" :value="$angka('blokir', $v['blokir'])" icon="x" tone="error"/>
        <x-stat-card label="Belum dianalisa" :value="$angka('belum', $v['belum'])" icon="clock" tone="gray"/>
        <x-stat-card label="Saran menunggu DPJP" :value="$angka('dpjp', $s['menunggu_dpjp'])" icon="user" tone="brand"/>
        <x-stat-card label="Biaya AI bulan ini" :value="$angka('biaya', 'US$ ' . number_format($s['biaya_ai'], 2))" icon="card" tone="info"/>
        @if ($vc = $s['vclaim'])
            <x-stat-card label="Pending verifikasi BPJS" :value="$vc['pending']" icon="clock" tone="error" data-vclaim-kartu="pending"/>
            <x-stat-card label="Klaim layak bulan ini" :value="$vc['layak']" icon="check" tone="success" data-vclaim-kartu="layak">
                <p class="num mt-3 text-xs text-gray-500" title="{{ $rpPenuh($vc['layak_total']) }}">{{ $rp($vc['layak_total']) }} disetujui</p>
            </x-stat-card>
            <x-stat-card label="Selisih diajukan vs disetujui bulan ini" :value="$selisihHtml($vc['selisih'])" icon="file" tone="gray" data-vclaim-kartu="selisih"/>
        @else
            <section class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-5 py-4 text-gray-500 sm:col-span-2 xl:col-span-3 dark:border-gray-700 dark:bg-white/[.02]"><p class="text-sm">Nilai klaim &amp; status BPJS — tersedia setelah VClaim tersambung</p></section>
        @endif
    </div>

    {{-- Rawat inap vs rawat jalan (mengikuti filter periode) --}}
    @if ($s['vclaim'])
        @php
            $periodeJenis = match ($period) {
                '7' => now()->subDays(7)->toDateString() . '..' . now()->toDateString(),
                '30' => now()->subDays(30)->toDateString() . '..' . now()->toDateString(),
                'semua' => '2000-01-01..' . now()->toDateString(),
                default => now()->format('Y-m'),
            };
        @endphp
        <x-jenis-split class="col-span-12" :data="app(\App\Services\Dashboard\BpjsStats::class)->perJenis($periodeJenis)"/>
    @endif

    {{-- Folder dipindahkan --}}
    <x-card class="col-span-12 min-w-0 xl:col-span-8" title="Folder dipindahkan dari FTP">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([['dipindahkan', 'Sudah dipindahkan (perkiraan diajukan)', $s['pindah']['dipindahkan']], ['pindah_belum_siap', 'Dipindah dalam status belum siap', $s['pindah']['belum_siap']], ['tanpa_periksa', 'Dipindah tanpa diperiksa CERMAT', $s['pindah']['tanpa_periksa']], ['median_hari', 'Median hari masuk FTP → dipindahkan', $s['pindah']['median_hari'] ?? '—']] as [$k, $l, $n])
                <div class="rounded-xl bg-gray-50 px-4 py-3 dark:bg-white/[.03]"><p class="text-xs text-gray-500">{{ $l }}</p><p class="num mt-1 text-xl font-semibold text-gray-800 dark:text-white/90" data-kartu="{{ $k }}">{{ $n }}</p><p class="mt-0.5 text-xs text-gray-400">perkiraan dari waktu folder hilang di FTP</p></div>
            @endforeach
        </div>
    </x-card>

    {{-- Antrean terbaru --}}
    <x-card class="col-span-12 min-w-0 md:col-span-6 xl:col-span-4" title="Antrean terbaru">
        <ul class="-my-3 divide-y divide-gray-100 dark:divide-gray-800" data-antrean>
            @forelse ($terbaru->take(5) as $f)
                @php [$sl, , $si, $sc] = $state[$f->state()]; @endphp
                <li><a href="{{ route('folders.show', $f) }}" class="flex items-center gap-3 py-3 hover:bg-gray-50 dark:hover:bg-white/5">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full {{ $sc }}" aria-hidden="true"><x-icon :name="$si" class="size-4"/></span>
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ $f->name }}</span>
                        <span class="block truncate text-xs text-gray-500">{{ $sl }}</span></span>
                    <span class="num shrink-0 text-xs text-gray-500">{{ $f->files_modified_at?->format('d/m H:i') ?? '—' }}</span>
                </a></li>
            @empty
                <li class="py-3 text-sm text-gray-500">Belum ada folder.</li>
            @endforelse
        </ul>
        <a href="{{ route('folders.index') }}" class="btn-secondary mt-4 flex w-full justify-center">Lihat semua</a>
    </x-card>

    {{-- Rubrik --}}
    <x-card class="col-span-12 min-w-0 md:col-span-6 xl:col-span-4" title="Butir rubrik paling sering kurang">
        <ul class="space-y-3 text-sm">
            @foreach ($s['rubrik'] as $r)
                <li data-rubrik="{{ $r['kode'] }}">
                    <div class="flex justify-between gap-2"><span class="truncate text-gray-700 dark:text-gray-300">{{ $r['label'] }}</span><span class="num font-medium text-gray-800 dark:text-white/90">{{ $r['n'] }}</span></div>
                    <div class="mt-1.5 h-2 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-2 rounded-full bg-brand-500" style="width: {{ round($r['n'] / $maxRubrik * 100, 1) }}%"></div></div>
                </li>
            @endforeach
        </ul>
    </x-card>

    {{-- Kategori ICD-10 3 digit dari saran kode --}}
    @if ($s['kode_aktif'])
        @foreach ([['kurang_klaim', 'Kemungkinan klaim kurang', 'Saran diagnosis sekunder berbukti yang belum tercantum di resume.'], ['kurang_bukti', 'Diagnosis kurang bukti', 'Folder dengan butir bukti diagnosis kurang, menurut kode diagnosis utama.']] as [$k, $judul, $ket])
            @php $d = $s[$k]; $mx = max(1, collect($d['items'])->max('n')); @endphp
            <x-card class="col-span-12 min-w-0 md:col-span-6 xl:col-span-4" :title="$judul" :subtitle="$ket" data-panel="{{ $k }}">
                @if (! $d['items'])
                    <p class="text-sm text-gray-500">Belum ada data.</p>
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($d['items'] as $i)
                            <li class="grid grid-cols-[3.5rem_1fr_2.5rem] items-center gap-3">
                                <strong class="num">{{ $i['kategori'] }}</strong>
                                <span class="h-2 rounded-full bg-gray-100 dark:bg-gray-800"><span class="block h-full rounded-full bg-warning-500" style="width: {{ round($i['n'] / $mx * 100, 1) }}%"></span></span>
                                <span class="num text-right">{{ $i['n'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($d['lain'])<p class="mt-3 text-xs text-gray-500">{{ $d['lain'] }} kategori lain</p>@endif
                @endif
            </x-card>
        @endforeach
        <p class="col-span-12 text-xs text-gray-500">Saran kode bukan keputusan koding; konfirmasi ke DPJP. Koding final oleh koder berdasarkan resume DPJP.</p>
    @endif

    {{-- Tabel terbaru --}}
    <x-card class="col-span-12 min-w-0" title="Folder terbaru" subtitle="Menurut waktu berkas berubah di FTP." :padding="false" data-folder-terbaru>
        <x-slot:actions><a href="{{ route('folders.index') }}" class="btn-secondary h-9">Lihat semua</a></x-slot:actions>
        @if ($terbaru->isEmpty())
            <p class="px-6 py-6 text-sm text-gray-500">Belum ada folder.</p>
        @else
            <x-table class="overflow-x-auto">
                <x-slot:head><tr><th>Folder</th><th>Sumber</th><th class="text-right">Butir kurang</th><th>Berkas berubah</th><th>Status</th><th class="w-10"><span class="sr-only">Aksi</span></th></tr></x-slot:head>
                @foreach ($terbaru as $f)
                    @php [$sl, $sv] = $state[$f->state()]; @endphp
                    <tr>
                        <td class="max-w-80 truncate font-medium text-gray-800 dark:text-white/90">{{ $f->name }}</td>
                        <td>{{ $f->isManual() ? 'Manual' : 'FTP' }}</td>
                        <td class="num text-right">{{ $f->rubric_kurang ?? '—' }}</td>
                        <td class="num whitespace-nowrap">{{ $f->files_modified_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td><x-badge :variant="$sv">{{ $sl }}</x-badge></td>
                        <td><a href="{{ route('folders.show', $f) }}" class="grid size-8 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5" aria-label="Buka folder"><x-icon name="dots" class="size-4"/></a></td>
                    </tr>
                @endforeach
            </x-table>
        @endif
    </x-card>
</div>
</x-app-layout>
