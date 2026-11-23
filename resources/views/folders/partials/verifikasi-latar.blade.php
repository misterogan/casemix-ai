{{-- Status verifikasi latar belakang satu berkas + tombol Jeda/Lanjutkan. Diganti utuh oleh polling (verifikasi-latar.js). --}}
@php
    $vs = $f->statusVerifikasi();
    $tampil = in_array($vs, ['antre', 'berjalan', 'menjeda', 'dijeda', 'terhenti', 'gagal'], true);
    [$label, $chip] = match ($vs) {
        'antre' => ['Dalam antrean', 'chip-proses'],
        'berjalan' => ['Sedang diperiksa', 'chip-proses'],
        'menjeda' => ['Menjeda…', 'chip-waspada'],
        'dijeda' => ['Dijeda', 'chip-belum'],
        'terhenti' => ['Terhenti', 'chip-kritis'],
        'gagal' => ['Gagal', 'chip-kritis'],
        default => [null, null],
    };
    $bolehAksi = $bolehAksi ?? (bool) auth()->user()?->can('folders.analyze');
@endphp
<span class="inline-flex flex-wrap items-center gap-1.5 align-middle" data-verif="{{ $f->id }}" data-verif-status="{{ $vs }}" data-verif-url="{{ route('verifikasi.latar.status') }}">
    @if ($tampil)
        <span class="chip {{ $chip }}" @if ($f->verifikasi_pesan) title="{{ $f->verifikasi_pesan }}" @endif>
            @if (in_array($vs, ['antre', 'berjalan', 'menjeda'], true))
                <svg class="size-3.5 motion-safe:animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
            @endif
            {{ $label }}
            @if ($f->verifikasi_file_total)
                <span class="num">· {{ $f->verifikasi_file_selesai }}/{{ $f->verifikasi_file_total }} file</span>
            @endif
        </span>
        @if ($vs === 'berjalan' && $f->verifikasi_file_aktif)
            <span class="max-w-[16rem] truncate text-xs font-normal text-abu" title="{{ $f->verifikasi_file_aktif }}">{{ $f->verifikasi_file_aktif }}</span>
        @elseif (in_array($vs, ['dijeda', 'gagal'], true) && $f->verifikasi_pesan)
            <span class="max-w-[20rem] truncate text-xs font-normal text-abu" title="{{ $f->verifikasi_pesan }}">{{ $f->verifikasi_pesan }}</span>
        @elseif ($vs === 'terhenti')
            <span class="text-xs font-normal text-abu">Tidak ada kabar &gt; {{ \App\Models\FtpFolder::HEARTBEAT_BASI_MENIT }} menit (mis. worker dimulai ulang)</span>
        @endif
        @if ($bolehAksi)
            @if (in_array($vs, ['antre', 'berjalan'], true))
                <form method="POST" action="{{ route('verifikasi.latar.jeda', $f) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-secondary !px-2 !py-0.5 text-xs">Jeda</button>
                </form>
            @elseif (in_array($vs, ['dijeda', 'terhenti', 'gagal'], true))
                <form method="POST" action="{{ route('verifikasi.latar.lanjut', $f) }}" class="inline">
                    @csrf
                    <button type="submit" class="btn-secondary !px-2 !py-0.5 text-xs">Lanjutkan</button>
                </form>
            @endif
        @endif
    @endif
</span>
