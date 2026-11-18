{{-- Riwayat pelayanan peserta dari VClaim (Koordinator). Param: $url, $noKartu, $histori (payload JSON service atau null). --}}
@php $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—'; @endphp
<x-panel aria-labelledby="judul-riwayat" data-panel-riwayat data-histori data-url="{{ $url }}">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
        <h2 id="judul-riwayat" class="font-semibold text-ink">Riwayat pelayanan peserta (90 hari)</h2>
        <button type="button" class="btn-secondary inline-flex items-center gap-2 text-sm" data-histori-ambil @disabled(! $noKartu)>Ambil riwayat pelayanan</button>
    </div>
    <div class="px-4 py-3 text-sm" data-histori-hasil aria-live="polite">
        @if (! $noKartu)
            <p class="text-xs text-ink-muted">Nomor kartu peserta belum diketahui; tunggu sinkron VClaim berikutnya.</p>
        @elseif (! $histori)
            <p class="text-xs text-ink-muted">Belum ada riwayat tersimpan. Klik Ambil riwayat pelayanan.</p>
        @else
            <div class="mb-3 flex flex-wrap items-center gap-2 text-xs text-ink-muted">
                <span>Diambil {{ $histori['fetched_label'] }}</span>
                @if ($histori['from_cache'])<span class="chip chip-belum">dari simpanan</span>@endif
                <button type="button" class="text-xs font-medium text-brand hover:underline" data-histori-perbarui>Perbarui</button>
            </div>
            <dl class="mb-3 grid grid-cols-4 gap-2 text-center">
                @foreach (['total' => 'Total', 'inap' => 'Rawat inap', 'jalan' => 'Rawat jalan', 'readmisi' => 'Readmisi'] as $k => $l)
                    <div class="rounded-md bg-paper px-2 py-1.5"><dt class="text-[11px] text-ink-muted">{{ $l }}</dt><dd class="tabular-nums font-semibold text-ink">{{ $histori['ringkasan'][$k] }}</dd></div>
                @endforeach
            </dl>
            @if (! $histori['items'])
                <p class="text-xs text-ink-muted">Tidak ada riwayat pelayanan dalam rentang {{ $tgl($histori['rentang']['mulai']) }}–{{ $tgl($histori['rentang']['akhir']) }}.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-left text-ink-muted"><tr><th class="py-1 pr-2 font-medium">Tgl SEP</th><th class="py-1 pr-2 font-medium">Tgl pulang</th><th class="py-1 pr-2 font-medium">Jenis</th><th class="py-1 pr-2 font-medium">PPK</th><th class="py-1 pr-2 font-medium">Diagnosa</th><th class="py-1 font-medium">Tanda</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($histori['items'] as $r)
                                <tr @class(['bg-brand-soft' => $r['sep_ini'], 'bg-kritis-muda' => $r['readmisi'] && ! $r['sep_ini']]) @if ($r['readmisi']) data-readmisi @endif>
                                    <td class="py-1 pr-2 tabular-nums">{{ $tgl($r['tgl_sep']) }}</td>
                                    <td class="py-1 pr-2 tabular-nums">{{ $tgl($r['tgl_plg_sep']) }}</td>
                                    <td class="py-1 pr-2">{{ $r['jns_pelayanan'] }}</td>
                                    <td class="py-1 pr-2">{{ $r['ppk_pelayanan'] }} @if ($r['rs_ini'])<span class="chip chip-proses ml-1">RS ini</span>@endif</td>
                                    <td class="py-1 pr-2">{{ $r['diagnosa'] }}</td>
                                    <td class="py-1">
                                        @if ($r['sep_ini'])<span class="chip chip-proses">SEP ini</span>@endif
                                        @if ($r['readmisi'])<span class="chip chip-kritis">Kemungkinan readmisi</span>@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </div>
</x-panel>
@vite('resources/js/bpjs-histori.js')
