{{-- Status klaim & riwayat pelayanan dari VClaim (hanya baca). Koordinator saja. --}}
@php $rp = fn ($n) => $n === null ? '—' : 'Rp ' . number_format((float) $n, 0, ',', '.'); @endphp
<x-card lapang aria-labelledby="judul-bpjs" data-panel-bpjs>
    <x-slot:header><h2 id="judul-bpjs" class="text-base font-semibold text-gray-800 dark:text-white/90">Status di BPJS</h2></x-slot:header>
    <div class="space-y-3 text-sm">
        @if ($bpjs['tanpa_sep'])
            <p class="text-abu" data-tanpa-sep>SEP belum ada</p>
        @elseif (! $k = $bpjs['klaim'])
            @include('folders.partials.bpjs-chip', ['kat' => null])
        @else
            <div class="flex flex-wrap items-center gap-2">
                @include('folders.partials.bpjs-chip', ['kat' => $folder->bpjs_status_kategori ?? $k->status_kategori])
                @if ($folder->bpjs_status_kategori === 'klaim')<span class="chip chip-sesuai" data-selesai-bpjs>Selesai di BPJS</span>
                @elseif ($folder->bpjs_tindak_lanjut)<span class="chip chip-kritis" data-tindak-bpjs>Perlu tindak lanjut BPJS</span>@endif
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
</x-card>

@unless ($bpjs['tanpa_sep'])
    @include('bpjs.partials.histori-panel', ['url' => route('folders.histori', $folder), 'noKartu' => $bpjs['no_kartu'], 'histori' => $bpjs['histori']])
@endunless
