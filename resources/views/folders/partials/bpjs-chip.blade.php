@php
    [$kelas, $teks] = match ((int) $kode) {
        1 => ['chip-waspada', 'Proses verifikasi'],
        2 => ['chip-kritis', 'Pending verifikasi'],
        3 => ['chip-sesuai', 'Klaim'],
        default => ['chip-belum', 'Belum ada di data BPJS'],
    };
@endphp
<span class="chip {{ $kelas }}" data-bpjs-status="{{ (int) $kode }}">{{ $teks }}</span>
