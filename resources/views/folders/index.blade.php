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
        ['siap' => 'Siap diajukan', 'belum_siap' => 'Belum siap'][$filters['kesiapan']] ?? null,
        ['belum' => 'Belum diajukan', 'final' => 'Final di E-Klaim', 'terkirim' => 'Terkirim ke BPJS', 'manual' => 'Ditandai diajukan (manual)'][$filters['eklaim']] ?? null,
    ]));
@endphp
<x-app-layout title="Verifikasi berkas">
    <div class="mx-auto max-w-[960px]">
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">Verifikasi berkas</h1>
        <p class="mt-1 text-xs text-abu">
            @if ($syncAt)
                Daftar folder dari FTP Enigma diperbarui otomatis tiap 15 menit · terakhir {{ \Illuminate\Support\Carbon::parse($syncAt)->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}
                @if (! ($lastSync['ok'] ?? true))<span class="text-kritis"> · gagal terhubung ke FTP, dicoba lagi jam berikutnya</span>@endif
            @else
                Daftar folder dari FTP Enigma diperbarui otomatis tiap 15 menit · belum pernah tersinkron
            @endif
        </p>

        {{-- Filter (GET); pencarian No. SEP ada di header --}}
        <form method="GET" action="{{ route('folders.index') }}" role="search" class="panel mt-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-[repeat(5,minmax(0,1fr))_auto] lg:items-end">
            @if ($tab !== 'aktif')<input type="hidden" name="tab" value="{{ $tab }}">@endif
            <input type="hidden" name="sep" value="{{ $filters['sep'] }}">
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
            <div>
                <label for="kesiapan" class="label">Status folder</label>
                <select id="kesiapan" name="kesiapan" class="field">
                    <option value="">Semua</option>
                    <option value="siap" @selected($filters['kesiapan'] === 'siap')>Siap diajukan</option>
                    <option value="belum_siap" @selected($filters['kesiapan'] === 'belum_siap')>Belum siap</option>
                </select>
            </div>
            <div>
                <label for="eklaim" class="label">Status pengajuan</label>
                <select id="eklaim" name="eklaim" class="field">
                    <option value="">Semua</option>
                    @foreach (['belum' => 'Belum diajukan', 'final' => 'Final di E-Klaim', 'terkirim' => 'Terkirim ke BPJS', 'manual' => 'Ditandai manual'] as $k => $l)
                        <option value="{{ $k }}" @selected($filters['eklaim'] === $k)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-1">
                <button type="submit" class="btn-primary">Cari</button>
                @if ($aktif)<a href="{{ route('folders.index') }}" class="btn-ghost">Hapus filter</a>@endif
            </div>
        </form>
        <p class="hint">Tanggal mengacu pada tanggal di FTP (waktu file terakhir diperbarui di Enigma).</p>

        <nav class="mt-5 flex gap-1 border-b border-garis-g text-sm" aria-label="Status folder">
            @foreach (['aktif' => 'Aktif', 'dipindahkan' => 'Sudah dipindahkan', 'hilang_tanpa_periksa' => 'Dipindah tanpa diperiksa'] as $k => $l)
                <a href="{{ route('folders.index', array_filter(['tab' => $k === 'aktif' ? null : $k] + $filters)) }}" data-tab="{{ $k }}"
                   @if ($tab === $k) aria-current="page" @endif
                   class="-mb-px border-b-2 px-3 py-2 {{ $tab === $k ? 'border-biru-aktif font-medium text-biru-aktif' : 'border-transparent text-abu hover:text-arang' }}">{{ $l }} <span class="num">({{ number_format($tabCounts[$k], 0, ',', '.') }})</span></a>
            @endforeach
        </nav>

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
                        <p class="mt-1 text-sm text-abu">Folder pasien dari FTP Enigma akan muncul setelah sinkronisasi otomatis berikutnya.</p>
                    @endif
                </div>
            @else
                @if ($bolehAksi)
                    {{-- Verifikasi massal: checkbox di tiap baris memakai form="form-massal" --}}
                    <form id="form-massal" method="POST" action="{{ route('verifikasi.latar.massal') }}" data-perkiraan-url="{{ route('verifikasi.latar.perkiraan') }}"
                          class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-gray-200 px-3 py-2 text-sm">
                        @csrf
                        <label class="inline-flex items-center gap-2"><input type="checkbox" class="size-4" data-pilih-semua> Pilih semua di halaman ini</label>
                        <label class="inline-flex items-center gap-2"><input type="checkbox" name="ulang" value="1" class="size-4" data-ulang> Analisa ulang yang sudah selesai</label>
                        <button type="submit" class="btn-primary" data-verifikasi-terpilih disabled>Verifikasi terpilih <span class="num" data-jumlah-terpilih></span></button>
                        <span class="text-xs text-abu">Maks {{ \App\Services\Verification\VerifikasiLatar::MAKS_MASSAL }} berkas per aksi; berjalan di latar belakang.</span>
                    </form>
                    @php $nAktif = ($latar['antre'] ?? 0) + ($latar['berjalan'] ?? 0); $nJeda = $latar['dijeda'] ?? 0; @endphp
                    @if ($nAktif || $nJeda || ($latar['menjeda'] ?? 0))
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                            <span class="num text-abu">Verifikasi latar: {{ $nAktif }} antre/berjalan · {{ $latar['menjeda'] ?? 0 }} menjeda · {{ $nJeda }} dijeda</span>
                            @if ($nAktif)
                                <form method="POST" action="{{ route('verifikasi.latar.jeda_semua') }}" data-konfirmasi="Jeda semua verifikasi yang antre/berjalan?">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Jeda semua</button>
                                </form>
                            @endif
                            @if ($nJeda)
                                <form method="POST" action="{{ route('verifikasi.latar.lanjut_semua') }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary">Lanjutkan semua</button>
                                </form>
                            @endif
                        </div>
                    @endif
                @endif
                <ol class="mt-1">
                    @foreach ($folders as $f)
                        @include('folders.partials.result-item', ['f' => $f, 'q' => '', 'massal' => $bolehAksi])
                    @endforeach
                </ol>
                {{ $folders->links('folders.partials.pagination') }}
            @endif
        </div>
    </div>
@vite(['resources/js/verifikasi-latar.js'])
</x-app-layout>
