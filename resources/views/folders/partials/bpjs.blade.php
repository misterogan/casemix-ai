{{-- Status klaim & riwayat pelayanan dari VClaim (hanya baca). Koordinator saja. --}}
@php $rp = fn ($n) => $n === null ? '—' : 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<x-panel aria-labelledby="judul-bpjs" data-panel-bpjs>
    <div class="border-b border-line px-4 py-3"><h2 id="judul-bpjs" class="font-semibold text-arang">Status di BPJS</h2></div>
    <div class="space-y-3 px-4 py-3 text-sm">
        @if ($bpjs['tanpa_sep'])
            <p class="text-abu" data-tanpa-sep>SEP belum ada</p>
        @elseif (! $k = $bpjs['klaim'])
            @include('folders.partials.bpjs-chip', ['kat' => null])
        @else
            <div class="flex flex-wrap items-center gap-2">
                @include('folders.partials.bpjs-chip', ['kat' => $k->status_kategori])
                @if ($k->no_fpk)<span class="text-xs text-abu">No FPK {{ $k->no_fpk }}</span>@endif
            </div>
            @if ($k->status_kategori === 'pending')
                <p class="text-xs text-kritis">Alasan pending tidak tersedia di VClaim. Lihat umpan balik verifikator.</p>
            @elseif (in_array($k->status_kategori, ['tidak_layak', 'dispute'], true))
                <p class="text-xs text-kritis">Alasan tidak tersedia di VClaim. Lihat umpan balik verifikator.</p>
            @endif
            @if ($k->inacbg_kode)
                <p class="text-arang"><span class="font-mono font-medium">{{ $k->inacbg_kode }}</span> {{ $k->inacbg_nama }}</p>
            @endif
            <table class="w-full text-xs">
                <tbody class="divide-y divide-gray-100">
                    @foreach (['by_pengajuan' => 'Diajukan', 'by_setujui' => 'Disetujui', 'by_tarif_gruper' => 'Tarif grouper', 'by_tarif_rs' => 'Tarif RS', 'by_topup' => 'Top-up'] as $f => $l)
                        <tr><th scope="row" class="py-1 text-left font-normal text-abu">{{ $l }}</th><td class="py-1 text-right tabular-nums text-arang">{{ $rp($k->$f) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-xs text-abu">diperbarui {{ $k->synced_at?->format('d/m/Y H:i') ?? '—' }}</p>
        @endif
    </div>
</x-panel>

@unless ($bpjs['tanpa_sep'])
<x-panel aria-labelledby="judul-riwayat" data-panel-riwayat>
    <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
        <h2 id="judul-riwayat" class="font-semibold text-arang">Riwayat pelayanan peserta (90 hari)</h2>
        <form method="GET" action="{{ route('folders.show', $folder) }}">
            <input type="hidden" name="ambil_riwayat" value="1">
            <button type="submit" class="btn-secondary text-sm" @disabled(! $bpjs['no_kartu'])>Ambil riwayat</button>
        </form>
    </div>
    <div class="px-4 py-3 text-sm">
        @if (session('status'))<p class="mb-2 text-xs text-biru-aktif" role="status">{{ session('status') }}</p>@endif
        @if (! $bpjs['no_kartu'])
            <p class="text-xs text-abu">Nomor kartu peserta belum diketahui; tunggu sinkron VClaim berikutnya.</p>
        @elseif ($bpjs['riwayat']->isEmpty())
            <p class="text-xs text-abu">Belum ada riwayat tersimpan. Klik Ambil riwayat.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="text-left text-abu"><tr><th class="py-1 pr-2 font-medium">Tgl SEP</th><th class="py-1 pr-2 font-medium">Tgl pulang</th><th class="py-1 pr-2 font-medium">Jenis</th><th class="py-1 pr-2 font-medium">PPK</th><th class="py-1 font-medium">Diagnosa</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($bpjs['riwayat'] as $r)
                            <tr @class(['bg-waspada-muda' => $r->readmisi]) @if ($r->readmisi) data-readmisi @endif>
                                <td class="py-1 pr-2 tabular-nums">{{ $r->tgl_sep?->format('d/m/Y') }}</td>
                                <td class="py-1 pr-2 tabular-nums">{{ $r->tgl_plg_sep?->format('d/m/Y') ?? '—' }}</td>
                                <td class="py-1 pr-2">{{ $r->jns_pelayanan }}</td>
                                <td class="py-1 pr-2">{{ $r->ppk_pelayanan }}</td>
                                <td class="py-1">{{ $r->diagnosa }}
                                    @if ($r->readmisi)<span class="chip chip-waspada mt-1">Kemungkinan readmisi</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-panel>
@endunless
