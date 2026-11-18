@php
    use App\Services\Dashboard\BpjsStats;
    $a = $stats['analisis'];
    $num = fn ($n) => number_format((float) $n, 0, ',', '.');
    $dec = fn ($n) => $n === null ? '—' : number_format((float) $n, 1, ',', '.');
    $pt = fn ($n) => BpjsStats::pctTeks($n);
    $panel = 'rounded-[10px] border border-line bg-white p-5';
    $ket = 'mt-1 text-xs text-ink-muted';
    $judulBagian = 'pt-2 text-lg font-semibold text-ink';
    $tidakBerlaku = 'Tidak berlaku untuk rawat jalan. Pilih jenis "Semua" atau "Rawat inap".';
@endphp

<section class="space-y-8" data-analisis>
    {{-- A. Severity --}}
    <section class="space-y-4" data-bagian="severity">
        <h2 class="{{ $judulBagian }}">Tingkat keparahan (severity) INA-CBG</h2>
        @if (! $a['severity'])
            <p class="text-sm text-ink-muted" data-tidak-berlaku>{{ $tidakBerlaku }}</p>
        @else
            @php $sv = $a['severity']; @endphp
            <p class="text-xs text-ink-muted">Rawat inap saja; severity dibaca dari akhiran kode INA-CBG (-I, -II, -III). Rata-rata pending rawat inap <span class="tabular">{{ $pt($sv['rata_pending']) }}</span>.</p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" data-kartu-severity>
                @foreach ($sv['kartu'] as $s => $c)
                    <a href="{{ route('bpjs.index', $dasarLink + ['sev' => $s]) }}" data-sev="{{ $s }}" class="{{ $panel }} block hover:border-biru-aktif">
                        <span class="text-sm text-ink-muted">Severity {{ $s }}</span>
                        <span class="tabular mt-1 block text-2xl font-semibold text-ink">{{ $num($c['n']) }}</span>
                        <span class="tabular block text-xs text-ink-muted">{{ $pt($c['pct_inap']) }} dari rawat inap</span>
                        <span class="tabular mt-1 inline-block rounded-[6px] px-2 py-0.5 text-xs {{ $c['tinggi'] ? 'bg-triage-red-soft text-triage-red' : 'bg-gray-100 text-ink-muted' }}" @if ($c['tinggi']) data-sev-tinggi @endif>{{ $pt($c['pct_pending']) }} pending</span>
                    </a>
                @endforeach
            </div>
            <x-rank-list tone="amber" judul="Kelompok INA-CBG severity III paling sering di-pending" :data="$sv['iii_pending']" :dasar="$dasarLink"
                arti="verifikator paling sering menahan klaim berat di kelompok ini."
                tindak="pastikan diagnosis sekunder dan tindakan yang menentukan severity didukung bukti di berkas." data-rank="sev3"/>
        @endif
    </section>

    {{-- B. Kasus & tarif --}}
    <section class="space-y-4" data-bagian="tarif">
        <h2 class="{{ $judulBagian }}">Kasus &amp; tarif</h2>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div data-toggle-grup>
                <div class="mb-2 inline-flex rounded-[6px] border border-line bg-white p-0.5 text-xs" role="group" aria-label="Urutkan menurut">
                    <button type="button" class="rounded-[4px] bg-brand-soft px-2 py-1 font-medium text-brand" data-toggle="jumlah" aria-pressed="true">Jumlah</button>
                    <button type="button" class="rounded-[4px] px-2 py-1 text-ink-muted" data-toggle="nilai" aria-pressed="false">Nilai</button>
                </div>
                <x-rank-list judul="INA-CBG terbanyak" :data="$a['tarif']['top_jumlah']" :dasar="$dasarLink" data-toggle-isi="jumlah" data-rank="top-jumlah"/>
                <x-rank-list judul="INA-CBG dengan nilai diajukan terbesar" :data="$a['tarif']['top_nilai']" :dasar="$dasarLink" data-toggle-isi="nilai" data-rank="top-nilai" hidden/>
            </div>
            <x-rank-list tone="red" chip="Informasi" judul="Selisih tarif RS di atas tarif INA-CBG" :data="$a['tarif']['selisih']" :dasar="$dasarLink"
                arti="biaya layanan RS lebih tinggi dari paket." tindak="bahan evaluasi clinical pathway, bukan koding." data-rank="selisih"/>
        </div>
    </section>

    {{-- C. Diagnosis --}}
    <section class="space-y-4" data-bagian="diagnosis">
        <h2 class="{{ $judulBagian }}">Diagnosis</h2>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-rank-list judul="Kategori diagnosis (3 digit)" :data="$a['diagnosis']['kategori']" :dasar="$dasarLink" data-rank="dx"/>
            <x-rank-list tone="amber" judul="Kategori diagnosis dengan pending tertinggi" :data="$a['diagnosis']['pending_tinggi']" :dasar="$dasarLink"
                arti="kategori dengan minimal {{ BpjsStats::MIN_KASUS }} kasus yang paling sering ditahan verifikator."
                tindak="cek pola umpan balik verifikator untuk kategori ini, tambahkan ke Acuan regulasi bila berulang." data-rank="dx-pending"/>
        </div>
    </section>

    {{-- D. Pending --}}
    @php $pd = $a['pending']; $maksE = max(1, ...array_values($pd['ember'])); @endphp
    <section class="space-y-4" data-bagian="pending">
        <h2 class="{{ $judulBagian }}">Pending</h2>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,22rem)_1fr]">
            <div class="{{ $panel }}" data-umur-pending>
                <h3 class="font-semibold text-ink">Umur pending</h3>
                <p class="{{ $ket }}">Hari sejak status berubah menjadi pending. Median <span class="tabular font-medium text-ink" data-median>{{ $dec($pd['median']) }}</span> hari, rata-rata <span class="tabular font-medium text-ink" data-rata>{{ $dec($pd['rata']) }}</span> hari.</p>
                <ul class="mt-3 space-y-2">
                    @foreach ($pd['ember'] as $label => $n)
                        <li data-ember="{{ $label }}"><div class="flex justify-between text-sm"><span>{{ $label }}</span><span class="tabular">{{ $num($n) }}</span></div>
                            <div class="mt-1 h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-triage-red" style="width: {{ round($n * 100 / $maksE, 1) }}%"></div></div></li>
                    @endforeach
                </ul>
            </div>
            <div class="{{ $panel }} overflow-x-auto" data-pending-terlama>
                <h3 class="font-semibold text-ink">10 pending terlama</h3>
                @if (! $pd['terlama'])
                    <p class="mt-2 text-sm text-ink-muted">Tidak ada klaim pending pada periode ini.</p>
                @else
                    <table class="mt-2 w-full text-sm">
                        <thead class="text-left text-xs text-ink-muted"><tr><th class="py-1 font-medium">No SEP</th><th class="py-1 font-medium">Pasien</th><th class="py-1 font-medium">INA-CBG</th><th class="py-1 text-right font-medium">Diajukan</th><th class="py-1 text-right font-medium">Umur</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($pd['terlama'] as $r)
                                <tr><td class="py-1"><a href="{{ route('bpjs.klaim.show', $r['no_sep']) }}" class="tabular text-biru-aktif underline">{{ $r['no_sep'] }}</a></td>
                                    <td class="py-1">{{ $r['nama'] }}</td><td class="tabular py-1">{{ $r['inacbg_kode'] ?: '—' }}</td>
                                    <td class="tabular py-1 text-right">{{ $r['by_pengajuan'] !== null ? BpjsStats::rupiahRingkas((float) $r['by_pengajuan']) : '—' }}</td>
                                    <td class="tabular py-1 text-right">{{ $num($r['umur']) }} hari</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </section>

    {{-- E. Mutu layanan --}}
    <section class="space-y-4" data-bagian="mutu">
        <h2 class="{{ $judulBagian }}">Mutu layanan</h2>
        @if (! $a['mutu'])
            <p class="text-sm text-ink-muted" data-tidak-berlaku>{{ $tidakBerlaku }}</p>
        @else
            @php $m = $a['mutu']; $re = $m['readmisi']; @endphp
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="{{ $panel }}" data-los>
                    <h3 class="font-semibold text-ink">Lama rawat (LOS) rawat inap</h3>
                    <p class="{{ $ket }}">Periode ini: median <span class="tabular font-medium text-ink" data-los-median>{{ $dec($m['los']['median_kini']) }}</span> hari, rata-rata <span class="tabular font-medium text-ink" data-los-rata>{{ $dec($m['los']['rata_kini']) }}</span> hari. Garis 12 bulan.</p>
                    <div class="relative mt-3 h-56"><canvas id="chart-los" role="img" aria-label="LOS rawat inap 12 bulan, median dan rata-rata hari."></canvas></div>
                </div>
                <x-rank-list judul="INA-CBG dengan LOS terpanjang" :data="$m['los_cbg']" :dasar="$dasarLink"
                    arti="rata-rata lama rawat per kelompok, minimal {{ BpjsStats::MIN_KASUS }} kasus." data-rank="los"/>
                <div class="{{ $panel }} xl:col-span-2" data-readmisi>
                    <div class="grid gap-4 md:grid-cols-[14rem_1fr]">
                        <div>
                            <h3 class="font-semibold text-ink">Readmisi internal ≤30 hari</h3>
                            <span class="tabular mt-2 block text-2xl font-semibold text-ink" data-readmisi-n>{{ $num($re['n']) }}</span>
                            <span class="tabular block text-xs text-ink-muted" data-readmisi-pct>{{ $pt($re['pct']) }} dari {{ $num($re['dasar']) }} SEP rawat inap</span>
                            <p class="mt-2 text-xs text-ink-muted">Rawat inap ulang dengan nomor kartu sama dalam 30 hari setelah pulang rawat inap. Hanya kunjungan di RS ini.</p>
                        </div>
                        <div class="relative h-48"><canvas id="chart-readmisi" role="img" aria-label="Persen readmisi internal 12 bulan."></canvas></div>
                    </div>
                </div>
            </div>
        @endif
    </section>

    {{-- F. Peran CERMAT --}}
    @php $cm = $a['cermat']; @endphp
    <section class="space-y-4" data-bagian="cermat">
        <h2 class="{{ $judulBagian }}">Peran CERMAT</h2>
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <div class="{{ $panel }}" data-cermat>
                <h3 class="font-semibold text-ink">% pending menurut hasil analisa folder</h3>
                <p class="{{ $ket }}">Dibandingkan bila tiap kelompok berisi minimal {{ BpjsStats::MIN_BANDING }} klaim.</p>
                <ul class="mt-3 space-y-3">
                    @foreach ($cm['kelompok'] as $k => $g)
                        <li data-cermat-kel="{{ $k }}"><div class="flex justify-between gap-2 text-sm"><span>{{ $g['label'] }} <span class="tabular text-xs text-ink-muted">n={{ $num($g['n']) }}</span></span>
                            <span class="tabular">@if ($g['cukup']){{ $pt($g['pct']) }}@else<span class="text-xs text-ink-muted" data-belum-cukup>Data belum cukup untuk dibandingkan</span>@endif</span></div>
                            @if ($g['cukup'])<div class="mt-1 h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-triage-red" style="width: {{ min(100, (float) $g['pct']) }}%"></div></div>@endif</li>
                    @endforeach
                </ul>
            </div>
            @if ($cm['golive'])
                @php $gl = $cm['golive']; @endphp
                <div class="{{ $panel }}" data-golive>
                    <h3 class="font-semibold text-ink">Sebelum dan sesudah CERMAT mulai ({{ \Illuminate\Support\Carbon::parse($gl['tanggal'])->format('d/m/Y') }})</h3>
                    <p class="{{ $ket }}">% pending klaim dengan tanggal pulang 3 bulan sebelum dan 3 bulan sesudah tanggal mulai.</p>
                    <dl class="mt-3 grid grid-cols-2 gap-4">
                        @foreach (['sebelum' => '3 bulan sebelum', 'sesudah' => '3 bulan sesudah'] as $k => $l)
                            <div><dt class="text-xs text-ink-muted">{{ $l }}</dt><dd class="tabular text-2xl font-semibold text-ink" data-golive-{{ $k }}>{{ $pt($gl[$k]['pct']) }}</dd>
                                <dd class="tabular text-xs text-ink-muted">n={{ $num($gl[$k]['n']) }}</dd></div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </div>
    </section>
</section>
