{{-- Tahap pengajuan berkas: Dianalisa -> Final di E-Klaim -> Terkirim ke BPJS (status VClaim tampil terpisah). --}}
@php
    [$kelas, $teks] = match ($f->tahapPengajuan()) {
        'terkirim' => ['chip-sesuai', 'Terkirim ke BPJS'],
        'final' => ['chip-proses', 'Final di E-Klaim'],
        'manual' => ['chip-proses', 'Sudah diajukan (manual)'],
        default => [null, null],
    };
@endphp
@if ($teks)
    <span class="chip {{ $kelas }} ml-1" data-eklaim-status="{{ $f->tahapPengajuan() }}">{{ $teks }}</span>
@endif
