@php
    $kat = $kat ?? null;
    [$kelas, $teks] = match ($kat) {
        'proses' => ['chip-waspada', 'Proses verifikasi'],
        'pending' => ['chip-kritis', 'Pending verifikasi'],
        'tidak_layak' => ['border-[#7F1D1D]/30 bg-[#FBE4E4] text-[#7F1D1D]', 'Tidak layak'],
        'dispute' => ['border-[#6B21A8]/25 bg-[#F3E8FF] text-[#6B21A8]', 'Dispute'],
        'klaim' => ['chip-sesuai', 'Klaim'],
        'lainnya' => ['border-[#9AA0A6]/40 bg-gray-100 text-abu', 'Lainnya'],
        default => ['chip-belum', 'Belum ada di data BPJS'],
    };
@endphp
<span class="chip {{ $kelas }}" data-bpjs-status="{{ $kat ?? 'belum' }}">{{ $teks }}</span>
