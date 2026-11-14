{{-- Isi halaman hasil verifikasi satu folder. Dipakai halaman penuh dan ditukar oleh polling saat analisa selesai. --}}
@php
    $active = $latest?->status?->isActive();
    $failed = $latest?->status === \App\Enums\ReviewStatus::Gagal;
    $order = ['kritis' => 0, 'perhatian' => 1, 'sesuai' => 2];
    $findings = collect($done?->findings ?? [])->sortBy(fn ($f) => $order[$f['tingkat'] ?? ''] ?? 3)->values();
    $masalah = $findings->filter(fn ($f) => ($f['tingkat'] ?? '') !== 'sesuai');
    $sesuai = $findings->filter(fn ($f) => ($f['tingkat'] ?? '') === 'sesuai');
    $docLink = function (?string $name, ?int $hal) use ($folder, $linkable) {
        if (! $name) return null;
        return isset($linkable[$name]) ? route('folders.file', [$folder, $linkable[$name]]) . ($hal ? '#page=' . $hal : '') : null;
    };
    $tahap = ['Mengunduh dokumen dari FTP…', 'Membaca dokumen…', 'Menyusun rekomendasi…'];
    $lewat = $active && $latest->created_at ? (int) abs($latest->created_at->diffInSeconds(now())) : 0;
    $jenisFile = fn (string $name) => match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'pdf' => 'pdf',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic' => 'gambar',
        default => 'berkas',
    };
@endphp

<header class="flex flex-wrap items-start justify-between gap-4">
    <div class="min-w-0">
        <p class="truncate text-sm text-url">FTP Enigma › {{ $folder->name }}</p>
        <h1 class="mt-1 text-[26px] leading-8 font-normal text-arang">{{ $folder->nama_pasien ?: $folder->name }}</h1>
        <p class="num mt-1 text-sm text-abu">
            RM {{ $folder->no_rm ?: '—' }} · SEP {{ $folder->no_sep ?: '—' }} · {{ \App\Support\Eklaim::JENIS_RAWAT[$folder->jenis_rawat] ?? 'Jenis rawat tidak terbaca' }} · {{ $folder->file_count }} dokumen
            @if ($folder->files_modified_at) · diperbarui di FTP {{ $folder->files_modified_at->translatedFormat('d M Y H:i') }}@endif
        </p>
    </div>
    @can('folders.analyze')
        @unless ($folder->missing_at)
            <form method="POST" action="{{ route('folders.analyze', $folder) }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled($active || $blocker) @if ($blocker) title="{{ $blocker }}" @endif>Analisa ulang dengan AI</button>
            </form>
        @endunless
    @endcan
</header>

<div class="mt-6 grid gap-8 lg:grid-cols-[1fr_300px]">
    <div class="min-w-0 space-y-4">
        @if ($folder->missing_at)
            <p class="rounded-lg border border-garis-g bg-white px-4 py-3 text-sm text-abu">Folder ini sudah tidak ada di FTP sejak {{ $folder->missing_at->translatedFormat('d M Y H:i') }}. Hasil analisa terakhir tetap ditampilkan.</p>
        @endif
        @if ($changed && ! $active)
            <p class="rounded-lg border border-stempel/25 bg-[#ECE9F7] px-4 py-3 text-sm text-stempel">
                <strong>File di folder ini berubah</strong> sejak dianalisa pada {{ $done->finished_at->translatedFormat('d M Y H:i') }}. Kalau dokumen sudah diperbaiki di Enigma, klik <em>Analisa ulang dengan AI</em>.
            </p>
        @endif
        @if ($blocker && ! $done && ! $active)
            <p class="rounded-lg border border-waspada/25 bg-waspada-muda px-4 py-3 text-sm text-waspada">Folder ini belum dianalisa. {{ $blocker }}</p>
        @endif

        <section class="panel overflow-hidden border-garis-g" aria-labelledby="hasil">
            @if ($active)<div class="bar-tak-tentu" aria-hidden="true"></div>@endif
            <div class="panel-head border-garis-g">
                <h2 id="hasil" class="panel-title text-arang">Rekomendasi AI</h2>
                @if ($done)<span class="text-xs text-abu">{{ $done->finished_at->translatedFormat('d M Y H:i') }} · {{ $done->requester?->name ?? 'Sistem' }}</span>@endif
            </div>

            @if ($active)
                <div class="border-b border-garis-g px-5 py-5"
                     data-poll="{{ route('folders.status', $folder) }}" data-poll-swap="#detail-folder" data-poll-partial="{{ route('folders.show', $folder) }}">
                    <p class="text-[15px] font-medium text-biru-aktif" aria-live="polite" data-tahap='@json($tahap)' data-lewat="{{ $lewat }}">{{ $tahap[min(count($tahap) - 1, intdiv($lewat, 8))] }}</p>
                    <p class="mt-1 text-sm text-abu">AI sedang membaca {{ $folder->file_count }} dokumen di folder ini. Biasanya 1–3 menit; hasil muncul di sini tanpa memuat ulang halaman.</p>
                </div>
            @endif
            @if ($failed && ! $active)
                <div class="border-b border-garis-g px-5 py-3 text-sm text-kritis"><p class="font-semibold">Analisa terakhir gagal.</p><p class="mt-0.5">{{ $latest->error }}</p></div>
            @endif

            @if ($done)
                <div @class(['transition-opacity duration-200', 'opacity-60' => $active])>
                    <div class="panel-body">
                        @if ($done->summary)<p class="max-w-[72ch] text-[15px] leading-relaxed text-arang">{{ $done->summary }}</p>@endif
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="chip chip-kritis">{{ $done->countBy('kritis') }} kritis</span>
                            <span class="chip chip-waspada">{{ $done->countBy('perhatian') }} perhatian</span>
                            <span class="chip chip-sesuai">{{ $done->countBy('sesuai') }} sesuai</span>
                        </div>
                    </div>

                    @if ($masalah->isNotEmpty())
                        <ul class="space-y-3 px-5 pb-5">
                            @foreach ($masalah as $f)
                                @include('folders.partials.finding', ['f' => $f])
                            @endforeach
                        </ul>
                    @endif

                    @if ($sesuai->isNotEmpty())
                        <details class="group border-t border-garis-g px-5 py-3">
                            <summary class="flex cursor-pointer list-none items-center gap-2 rounded-md text-sm font-medium text-sesuai [&::-webkit-details-marker]:hidden">
                                <svg class="size-4 transition-transform duration-200 group-open:rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                {{ $sesuai->count() }} bagian sudah sesuai
                            </summary>
                            <ul class="mt-3 space-y-3 pb-2">
                                @foreach ($sesuai as $f)
                                    @include('folders.partials.finding', ['f' => $f])
                                @endforeach
                            </ul>
                        </details>
                    @endif

                    @if (! empty($done->suggestions))
                        <div class="border-t border-garis-g px-5 py-4">
                            <h3 class="font-semibold text-arang">Kondisi yang belum tertulis sebagai diagnosis</h3>
                            <p class="mt-0.5 text-sm text-abu">Konfirmasi ke DPJP. Bukan diagnosis dan bukan kode ICD.</p>
                            <ul class="mt-3 space-y-3">
                                @foreach ($done->suggestions as $s)
                                    @php $url = $docLink($s['dokumen'] ?? null, $s['halaman'] ?? null); @endphp
                                    <li class="kartu-temuan border-l-biru">
                                        <p class="font-semibold text-arang">{{ $s['kondisi'] }}</p>
                                        <p class="mt-1 text-sm text-abu"><span class="font-medium text-arang">Bukti:</span> {{ $s['bukti'] }}</p>
                                        @if (! empty($s['dokumen']))
                                            <p class="mt-2 text-sm">@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener" class="tautan-g">{{ $s['dokumen'] }}@if (! empty($s['halaman'])) · hal. {{ $s['halaman'] }}@endif ↗</a>@else<span class="text-abu">{{ $s['dokumen'] }}</span>@endif</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($done->extracted))
                        <details class="border-t border-garis-g px-5 py-3">
                            <summary class="cursor-pointer rounded-md text-sm font-medium text-abu">Data yang terbaca dari dokumen (dasar aturan E-Klaim)</summary>
                            <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                                @foreach ($done->extracted as $field => $v)
                                    <div class="flex justify-between gap-3 border-b border-garis-g/70 py-1"><dt class="text-abu">{{ \App\Support\ClaimFields::label($field) }}</dt><dd class="num text-right text-arang">{{ \App\Support\ClaimFields::display($field, $v) }}</dd></div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                </div>
            @elseif (! $active && ! $failed && ! $blocker)
                <p class="px-5 py-6 text-sm text-abu">Belum ada hasil analisa.</p>
            @endif
        </section>

        <p class="text-xs text-abu">AI hanya memberi rekomendasi dan tidak memberi kode ICD. Perbaikan dokumen dilakukan di Enigma oleh yang berwenang; setelah itu folder ini akan tertanda <em>File berubah</em> pada sinkronisasi berikutnya.</p>
    </div>

    <aside>
        <section class="panel border-garis-g">
            <div class="panel-head border-garis-g"><h2 class="panel-title text-arang">Dokumen di folder</h2><span class="num text-xs text-abu">{{ $folder->file_count }}</span></div>
            <ul class="py-1">
                @foreach ($folder->files as $i => $file)
                    @php $jenis = $jenisFile($file['name']); @endphp
                    <li>
                        <a href="{{ route('folders.file', [$folder, $i]) }}" target="_blank" rel="noopener"
                           class="group flex items-center gap-3 px-4 py-2 transition-colors duration-150 hover:bg-abu-muda">
                            @if ($jenis === 'pdf')
                                <svg class="size-5 shrink-0 text-kritis" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 2h8l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z" fill="currentColor" opacity=".12"/><path d="M6 2h8l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z M14 2v5h5" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><text x="12" y="17" text-anchor="middle" font-size="5.5" font-weight="700" fill="currentColor" font-family="sans-serif">PDF</text></svg>
                            @elseif ($jenis === 'gambar')
                                <svg class="size-5 shrink-0 text-biru" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 9" stroke-linejoin="round"/></svg>
                            @else
                                <svg class="size-5 shrink-0 text-abu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M6 2h8l5 5v13a2 2 0 01-2 2H6a2 2 0 01-2-2V4a2 2 0 012-2z M14 2v5h5" stroke-linejoin="round"/></svg>
                            @endif
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm text-tautan group-hover:underline">{{ $file['name'] }}</span>
                                <span class="num block text-xs text-abu">{{ \App\Services\Import\DocumentGuesser::type($file['name'])->label() }} · {{ number_format($file['size'] / 1024) }} KB</span>
                            </span>
                            <span class="text-xs text-abu" aria-hidden="true">↗</span>
                        </a>
                    </li>
                @endforeach
            </ul>
            <p class="border-t border-garis-g px-4 py-2.5 text-xs text-abu">Dibuka langsung dari FTP Enigma, tidak disalin.</p>
        </section>
    </aside>
</div>
