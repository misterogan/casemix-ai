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
                <form method="POST" action="{{ route('verifikasi.latar.jeda', $f) }}" class="inline" data-verif-aksi>
                    @csrf
                    <button type="submit" class="btn-secondary inline-flex items-center gap-1 !px-2 !py-0.5 text-xs" data-tombol-jeda>
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="5" width="4" height="14" rx="1"/></svg>Jeda
                    </button>
                </form>
            @elseif (in_array($vs, ['dijeda', 'terhenti', 'gagal'], true))
                <form method="POST" action="{{ route('verifikasi.latar.lanjut', $f) }}" class="inline" data-verif-aksi data-lanjut>
                    @csrf
                    <button type="submit" class="btn-secondary inline-flex items-center gap-1 !px-2 !py-0.5 text-xs" data-tombol-lanjut>
                        <svg class="size-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10-6.5a1 1 0 0 0 0-1.72l-10-6.5A1 1 0 0 0 8 5.5Z"/></svg>Lanjutkan
                    </button>
                </form>
            @endif
        @endif
    @endif
</span>
