{{-- Alur pengajuan: Dianalisa -> Final di E-Klaim -> Terkirim ke BPJS -> status VClaim. Sumber: otomatis (E-Klaim, hanya baca) atau manual. --}}
@php
    $tahap = $folder->tahapPengajuan();
    $urut = ['dianalisa' => 1, 'manual' => 2, 'final' => 2, 'terkirim' => 3][$tahap] ?? 0;
    $langkah = [1 => 'Dianalisa', 2 => 'Final di E-Klaim', 3 => 'Terkirim ke BPJS'];
@endphp
<div class="mt-2 flex flex-wrap items-center gap-1 text-xs" data-alur-pengajuan="{{ $tahap ?? 'belum' }}">
    @foreach ($langkah as $i => $l)
        @if ($i > 1)<span class="text-abu" aria-hidden="true">→</span>@endif
        <span @class(['chip', 'chip-sesuai' => $i <= $urut, 'chip-belum' => $i > $urut])>{{ $i === 2 && $tahap === 'manual' ? 'Sudah diajukan' : $l }}</span>
    @endforeach
    @if ($folder->bpjs_status_kategori)
        <span class="text-abu" aria-hidden="true">→</span>
        @include('folders.partials.bpjs-chip', ['kat' => $folder->bpjs_status_kategori])
    @endif
    @if ($folder->eklaim_status)
        <span class="text-abu" data-eklaim-sumber="otomatis">Sumber: E-Klaim (otomatis){{ $folder->eklaim_dicek_at ? ', dicek ' . $folder->eklaim_dicek_at->translatedFormat('d M Y H:i') : '' }}</span>
    @elseif ($folder->diajukan_manual_at)
        <span class="text-abu" data-eklaim-sumber="manual">Sumber: manual oleh {{ $folder->diajukanManualOleh?->name ?? '—' }}, {{ $folder->diajukan_manual_at->translatedFormat('d M Y H:i') }}</span>
        @can('folders.analyze')
            <form method="POST" action="{{ route('folders.diajukan.batal', $folder) }}" class="inline">@csrf @method('DELETE')<button type="submit" class="underline text-abu">Batalkan</button></form>
        @endcan
    @elseif ($folder->no_sep)
        @can('folders.analyze')
            <form method="POST" action="{{ route('folders.diajukan', $folder) }}" class="inline" data-tandai-diajukan>@csrf<button type="submit" class="btn-ghost text-xs">Tandai sudah diajukan</button></form>
        @endcan
    @endif
</div>
