@php
    use App\Services\Dashboard\BpjsStats;
    $a = $stats['analisis'];
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $dec = fn ($n) => $n === null ? '—' : number_format((float) $n, 1, ',', '.');
    $pt = fn ($n) => BpjsStats::pctTeks($n);
    $tidakBerlaku = 'Tidak berlaku untuk rawat jalan. Pilih jenis "Semua" atau "Rawat inap".';
@endphp

<section class="grid grid-cols-1 gap-4 md:gap-6 xl:grid-cols-2" data-analisis>
    @if ($sv = $a['severity'])
        <x-card title="Severity III" class="min-w-0 xl:col-span-2">
            <x-rank-list tone="amber" judul="Kelompok INA-CBG severity III paling sering di-pending" :data="$sv['iii_pending']" :dasar="$dasarLink"
                arti="verifikator paling sering menahan klaim berat di kelompok ini."
                tindak="pastikan diagnosis sekunder dan tindakan yang menentukan severity didukung bukti di berkas." data-rank="sev3"/>
        </x-card>
    @endif

    {{-- Kasus & tarif --}}
    <x-card title="INA-CBG teratas" subtitle="Urutkan menurut jumlah klaim atau nilai diajukan." class="min-w-0" data-bagian="tarif">
        <div data-toggle-grup>
            <div class="mb-3 inline-flex rounded-lg bg-gray-100 p-0.5 text-xs dark:bg-gray-800" role="group" aria-label="Urutkan menurut">
                <button type="button" class="rounded-md bg-white px-2.5 py-1 font-medium text-gray-800 shadow-xs dark:bg-gray-900 dark:text-white/90" data-toggle="jumlah" aria-pressed="true">Jumlah</button>
                <button type="button" class="rounded-md px-2.5 py-1 text-gray-500" data-toggle="nilai" aria-pressed="false">Nilai</button>
            </div>
            <x-rank-list judul="INA-CBG terbanyak" :data="$a['tarif']['top_jumlah']" :dasar="$dasarLink" data-toggle-isi="jumlah" data-rank="top-jumlah"/>
            <x-rank-list judul="INA-CBG dengan nilai diajukan terbesar" :data="$a['tarif']['top_nilai']" :dasar="$dasarLink" data-toggle-isi="nilai" data-rank="top-nilai" hidden/>
        </div>
    </x-card>
    <x-card title="Selisih tarif" class="min-w-0">
        <x-rank-list tone="red" chip="Informasi" judul="Selisih tarif RS di atas tarif INA-CBG" :data="$a['tarif']['selisih']" :dasar="$dasarLink"
            arti="biaya layanan RS lebih tinggi dari paket." tindak="bahan evaluasi clinical pathway, bukan koding." data-rank="selisih"/>
    </x-card>


    {{-- Diagnosis --}}
    <x-card title="Diagnosis" class="min-w-0" data-bagian="diagnosis">
        <x-rank-list judul="Kategori diagnosis (3 digit)" :data="$a['diagnosis']['kategori']" :dasar="$dasarLink" data-rank="dx"/>
    </x-card>
    <x-card title="Diagnosis dengan pending tertinggi" class="min-w-0">
        <x-rank-list tone="amber" judul="Kategori diagnosis dengan pending tertinggi" :data="$a['diagnosis']['pending_tinggi']" :dasar="$dasarLink"
            arti="kategori dengan minimal {{ BpjsStats::MIN_KASUS }} kasus yang paling sering ditahan verifikator."
            tindak="cek pola umpan balik verifikator untuk kategori ini, tambahkan ke Acuan regulasi bila berulang." data-rank="dx-pending"/>
    </x-card>

    {{-- Mutu layanan --}}
    @if (! $a['mutu'])
        <x-card title="Mutu layanan" class="min-w-0 xl:col-span-2" data-bagian="mutu"><p class="text-sm text-gray-500" data-tidak-berlaku>{{ $tidakBerlaku }}</p></x-card>
    @else
        @php $m = $a['mutu']; $re = $m['readmisi']; @endphp
        <x-card title="Lama rawat (LOS) rawat inap" class="min-w-0" data-bagian="mutu" data-los>
            <p class="-mt-2 text-xs text-gray-500">Periode ini: median <span class="num font-medium text-gray-800 dark:text-white/90" data-los-median>{{ $dec($m['los']['median_kini']) }}</span> hari, rata-rata <span class="num font-medium text-gray-800 dark:text-white/90" data-los-rata>{{ $dec($m['los']['rata_kini']) }}</span> hari. Garis 12 bulan.</p>
            <div class="relative mt-3 h-56"><canvas id="chart-los" role="img" aria-label="LOS rawat inap 12 bulan, median dan rata-rata hari."></canvas></div>
        </x-card>
        <x-card title="LOS terpanjang" class="min-w-0">
            <x-rank-list judul="INA-CBG dengan LOS terpanjang" :data="$m['los_cbg']" :dasar="$dasarLink"
                arti="rata-rata lama rawat per kelompok, minimal {{ BpjsStats::MIN_KASUS }} kasus." data-rank="los"/>
        </x-card>
        <x-card title="Readmisi internal ≤30 hari" class="min-w-0 xl:col-span-2" data-readmisi>
            <div class="grid gap-4 md:grid-cols-[14rem_1fr]">
                <div>
                    <span class="num block text-[34px] leading-10 font-semibold text-gray-800 dark:text-white/90" data-readmisi-n>{{ $num($re['n']) }}</span>
                    <span class="num block text-xs text-gray-500" data-readmisi-pct>{{ $pt($re['pct']) }} dari {{ $num($re['dasar']) }} SEP rawat inap</span>
                    <p class="mt-2 text-xs text-gray-500">Rawat inap ulang dengan nomor kartu sama dalam 30 hari setelah pulang rawat inap. Hanya kunjungan di RS ini.</p>
                </div>
                <div class="relative h-48 min-w-0"><canvas id="chart-readmisi" role="img" aria-label="Persen readmisi internal 12 bulan."></canvas></div>
            </div>
        </x-card>
    @endif

    {{-- Peran CERMAT --}}
    @php $cm = $a['cermat']; @endphp
    <x-card title="Peran CERMAT: % pending menurut hasil analisa folder" subtitle="Dibandingkan bila tiap kelompok berisi minimal {{ BpjsStats::MIN_BANDING }} klaim." class="min-w-0" data-bagian="cermat" data-cermat>
        <ul class="space-y-4">
            @foreach ($cm['kelompok'] as $k => $g)
                <li data-cermat-kel="{{ $k }}"><div class="flex justify-between gap-2 text-sm"><span class="text-gray-700 dark:text-gray-300">{{ $g['label'] }} <span class="num text-xs text-gray-500">n={{ $num($g['n']) }}</span></span>
                    <span class="num font-medium text-gray-800 dark:text-white/90">@if ($g['cukup']){{ $pt($g['pct']) }}@else<span class="text-xs font-normal text-gray-500" data-belum-cukup>Data belum cukup untuk dibandingkan</span>@endif</span></div>
                    @if ($g['cukup'])<div class="mt-2 h-2 rounded-full bg-gray-100 dark:bg-gray-800"><div class="h-2 rounded-full bg-error-500" style="width: {{ min(100, (float) $g['pct']) }}%"></div></div>@endif</li>
            @endforeach
        </ul>
    </x-card>
    @if ($cm['golive'])
        @php $gl = $cm['golive']; @endphp
        <x-card title="Sebelum dan sesudah CERMAT mulai ({{ \Illuminate\Support\Carbon::parse($gl['tanggal'])->format('d/m/Y') }})" subtitle="% pending klaim dengan tanggal pulang 3 bulan sebelum dan 3 bulan sesudah tanggal mulai." class="min-w-0" data-golive>
            <dl class="grid grid-cols-2 gap-4">
                @foreach (['sebelum' => '3 bulan sebelum', 'sesudah' => '3 bulan sesudah'] as $k => $l)
                    <div><dt class="text-xs text-gray-500">{{ $l }}</dt><dd class="num mt-1 text-[28px] leading-9 font-semibold text-gray-800 dark:text-white/90" data-golive-{{ $k }}>{{ $pt($gl[$k]['pct']) }}</dd>
                        <dd class="num text-xs text-gray-500">n={{ $num($gl[$k]['n']) }}</dd></div>
                @endforeach
            </dl>
        </x-card>
    @endif
</section>
