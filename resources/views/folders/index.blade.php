@php
    $syncAt = $lastSync['at'] ?? null;
    $aktif = array_filter($filters, fn ($v) => filled($v));
    $tgl = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->translatedFormat('d M Y') : null;
    $ringkas = array_values(array_filter([
        $filters['sep'] ? 'No. SEP mengandung "' . $filters['sep'] . '"' : null,
        match (true) {
            $filters['dari'] && $filters['sampai'] => 'Tanggal di FTP ' . $tgl($filters['dari']) . ' – ' . $tgl($filters['sampai']),
            (bool) $filters['dari'] => 'Tanggal di FTP sejak ' . $tgl($filters['dari']),
            (bool) $filters['sampai'] => 'Tanggal di FTP sampai ' . $tgl($filters['sampai']),
            default => null,
        },
        ['sudah' => 'Sudah diperiksa AI', 'belum' => 'Belum diperiksa AI'][$filters['periksa']] ?? null,
    ]));
@endphp
<x-app-layout title="Verifikasi berkas">
    <div class="mx-auto max-w-[960px]">
        <h1 class="text-[26px] leading-8 font-normal text-arang">Verifikasi berkas</h1>
        <p class="mt-1 text-xs text-abu">
            @if ($syncAt)
                Daftar folder dari FTP DHealth diperbarui otomatis tiap jam · terakhir {{ \Illuminate\Support\Carbon::parse($syncAt)->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}
                @if (! ($lastSync['ok'] ?? true))<span class="text-kritis"> · gagal terhubung ke FTP, dicoba lagi jam berikutnya</span>@endif
            @else
                Daftar folder dari FTP DHealth diperbarui otomatis tiap jam · belum pernah tersinkron
            @endif
        </p>

        {{-- Satu-satunya isian di halaman ini: filter pencarian (GET) --}}
        <form method="GET" action="{{ route('folders.index') }}" role="search" class="panel mt-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end">
            <div>
                <label for="sep" class="label">No. SEP</label>
                <input id="sep" name="sep" value="{{ $filters['sep'] }}" maxlength="30" class="field num" placeholder="Contoh 0030R0011126V000143" autocomplete="off" spellcheck="false" autofocus>
            </div>
            <div>
                <label for="dari" class="label">Dari tanggal</label>
                <input id="dari" name="dari" type="date" value="{{ $filters['dari'] }}" class="field num">
            </div>
            <div>
                <label for="sampai" class="label">Sampai tanggal</label>
                <input id="sampai" name="sampai" type="date" value="{{ $filters['sampai'] }}" class="field num">
            </div>
            <div>
                <label for="periksa" class="label">Status pemeriksaan</label>
                <select id="periksa" name="periksa" class="field">
                    <option value="">Semua</option>
                    <option value="sudah" @selected($filters['periksa'] === 'sudah')>Sudah diperiksa AI</option>
                    <option value="belum" @selected($filters['periksa'] === 'belum')>Belum diperiksa AI</option>
                </select>
            </div>
            <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-1">
                <button type="submit" class="btn-primary">Cari</button>
                @if ($aktif)<a href="{{ route('folders.index') }}" class="btn-ghost">Hapus filter</a>@endif
            </div>
        </form>
        <p class="hint">Tanggal mengacu pada tanggal di FTP (waktu file terakhir diperbarui di DHealth).</p>

        <div class="mt-6">
            <p class="num text-sm text-abu" aria-live="polite">
                <span class="font-medium text-arang">{{ number_format($folders->total(), 0, ',', '.') }} folder ditemukan</span>
                @if ($ringkas) · {{ implode(' · ', $ringkas) }}@endif
            </p>
            @unless ($filters['periksa'])
                <p class="num mt-0.5 text-xs text-abu">{{ number_format($counts['sudah'], 0, ',', '.') }} sudah diperiksa AI · {{ number_format($counts['belum'], 0, ',', '.') }} belum diperiksa AI</p>
            @endunless

            @if ($folders->isEmpty())
                <div class="py-12 text-center">
                    @if ($aktif)
                        <p class="text-arang">Tidak ada folder yang cocok dengan filter ini.</p>
                        <p class="mt-1 text-sm text-abu">Periksa No. SEP, perlebar rentang tanggal, atau hapus filter.</p>
                    @else
                        <p class="text-arang">Belum ada folder.</p>
                        <p class="mt-1 text-sm text-abu">Folder pasien dari FTP DHealth akan muncul setelah sinkronisasi otomatis berikutnya.</p>
                    @endif
                </div>
            @else
                <ol class="mt-1">
                    @foreach ($folders as $f)
                        @include('folders.partials.result-item', ['f' => $f, 'q' => ''])
                    @endforeach
                </ol>
                {{ $folders->links('folders.partials.pagination') }}
            @endif
        </div>
    </div>
</x-app-layout>
