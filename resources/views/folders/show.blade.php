<x-app-layout :title="$folder->nama_pasien ?: $folder->name" :full-height="true">
<div class="flex h-full min-h-0 flex-col">
    {{-- Pencarian kecil: kembali ke daftar dengan kata kunci --}}
    <form method="GET" action="{{ route('folders.index') }}" role="search" class="max-w-[692px] shrink-0">
        <div class="cari">
            <button type="submit" class="cari-ikon" aria-label="Cari">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
            </button>
            <label for="q" class="sr-only">Cari folder pasien</label>
            <input id="q" name="q" type="search" value="{{ request()->query('q') }}" class="cari-input"
                   placeholder="Cari No. SEP, No. RM, atau nama pasien" autocomplete="off" spellcheck="false">
        </div>
    </form>

    <p class="sr-only" aria-live="polite" data-kabar></p>

    @php
        $berjalan = (bool) $latest?->status?->isActive();
        $selesai = $documents->reject(fn ($d) => $d->status->isActive())->count();
        $total = $documents->count();
        $kunci = $berjalan && ! $blocker; // tombol yang dibuka JS saat analisa selesai
        $siap = $folder->ready();
        $menunggu = $saranOn ? $suggestions->whereIn('status', ['baru', 'ditanyakan'])->count() : 0;
        $tautan = fn (?string $dok, ?int $hal) => $dok !== null && isset($linkable[$dok]) ? route('folders.file', [$folder, $linkable[$dok]]) . ($hal ? '#page=' . $hal : '') : null;
    @endphp

    {{-- Urutan: kepala → status → Daftar periksa → Saran DPJP → detail analisa → file. Halaman dimuat ulang saat analisa selesai. --}}
    <div class="mt-6 flex min-h-0 flex-1 flex-col gap-6" data-docs data-muat-ulang="1" data-status-url="{{ route('folders.status', $folder) }}" data-active="{{ $berjalan ? '1' : '0' }}">
        <header class="flex shrink-0 flex-wrap items-start justify-between gap-4" data-kepala>
            <div class="min-w-0">
                <p class="truncate text-sm text-url">FTP Enigma › {{ $folder->name }}</p>
                <h1 class="mt-1 text-[26px] leading-8 font-normal text-arang">{{ $folder->nama_pasien ?: $folder->name }}</h1>
                <p class="num mt-1 text-sm text-abu">
                    RM {{ $folder->no_rm ?: '—' }} · SEP {{ $folder->no_sep ?: '—' }} · {{ \App\Support\Eklaim::JENIS_RAWAT[$folder->jenis_rawat] ?? 'Jenis rawat tidak terbaca' }} · {{ $folder->file_count }} dokumen
                    @if ($folder->files_modified_at) · diperbarui di FTP {{ $folder->files_modified_at->translatedFormat('d M Y H:i') }}@endif
                </p>
                @if ($menunggu)
                    <p class="mt-1 text-sm font-medium text-purple-800">Saran DPJP: {{ $menunggu }} menunggu tindak lanjut</p>
                @endif
            </div>
            @can('folders.analyze')
                @unless ($folder->missing_at)
                    <div class="flex flex-wrap gap-2">
                        {{-- Siap diajukan: analisa ulang disembunyikan kecuali ada file berubah --}}
                        @unless ($siap === true && ! $changed && ! $berjalan)
                            <form method="POST" action="{{ route('folders.analyze', $folder) }}">
                                @csrf
                                <button type="submit" class="btn-primary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker) @if ($blocker) title="{{ $blocker }}" @endif>Analisa ulang dengan AI</button>
                            </form>
                        @endunless
                        @if ($total)
                            <form method="POST" action="{{ route('folders.process_all', $folder) }}" data-konfirmasi="Semua file akan dibaca ulang dan memakai token. Lanjutkan?">
                                @csrf
                                <button type="submit" class="btn-secondary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>Proses ulang semua file</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('folders.resynthesize', $folder) }}" data-resynth{{ $canResynth ? '' : ' hidden' }}>
                            @csrf
                            <button type="submit" class="btn-secondary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>Ulangi rekomendasi keseluruhan</button>
                        </form>
                    </div>
                @endunless
            @endcan
        </header>

        {{-- Status besar: hanya dari Daftar periksa (saran DPJP tidak ikut menentukan) --}}
        @if ($siap !== null)
            <div @class(['shrink-0 rounded-lg border px-5 py-4', 'border-green-300 bg-green-50' => $siap, 'border-red-300 bg-red-50' => ! $siap]) data-status-folder>
                <p @class(['text-xl font-semibold', 'text-green-800' => $siap, 'text-red-800' => ! $siap])>
                    {{ $siap ? 'Siap diajukan' : 'Belum siap · ' . $folder->rubric_kurang . ' butir kurang' }}
                </p>
                @if ($changed)<p class="mt-1 text-sm text-abu">File berubah sejak analisa terakhir. Klik Analisa ulang untuk menilai butir terkait.</p>@endif
            </div>
        @endif

        {{-- lg+: dua panel setinggi layar, masing-masing scroll sendiri --}}
        <div class="grid gap-6 lg:min-h-0 lg:flex-1 lg:grid-cols-2">
        <div class="min-h-0 space-y-6 lg:overflow-y-auto lg:overscroll-contain">
        {{-- Daftar periksa: 14 butir tetap per kelompok --}}
        <section class="panel" aria-labelledby="judul-periksa" data-daftar-periksa>
            <div class="panel-head border-b border-gray-200 px-4 py-3">
                <h2 id="judul-periksa" class="font-semibold text-arang">Daftar periksa</h2>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach ($rubric as $kelompok => $butir)
                    <div class="px-4 py-3">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-abu">{{ $kelompok }}</h3>
                        <ul class="mt-2 space-y-2">
                            @foreach ($butir as $i)
                                @php
                                    [$ikon, $warna, $teks] = match ($i->status) {
                                        'lengkap' => ['✓', 'text-green-700', 'Lengkap'],
                                        'kurang' => ['✕', 'text-red-700', 'Kurang'],
                                        'tidak_berlaku' => ['–', 'text-gray-500', 'Tidak berlaku'],
                                        'diterima' => ['✓', 'text-blue-700', 'Diterima'],
                                        default => ['○', 'text-gray-400', 'Belum dinilai'],
                                    };
                                    $url = $tautan($i->dokumen, $i->halaman);
                                @endphp
                                <li class="flex items-start gap-3" data-butir="{{ $i->kode }}" data-butir-status="{{ $i->status ?? 'belum' }}">
                                    <span class="mt-0.5 w-4 shrink-0 text-center font-bold {{ $warna }}" aria-hidden="true">{{ $ikon }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm text-arang">{{ $i->label() }}<span class="sr-only">: {{ $teks }}</span>
                                            @unless ($i->required())<span class="text-xs text-abu">(bila ada)</span>@endunless
                                        </p>
                                        @if ($i->catatan || $i->dokumen)
                                            <p class="text-xs text-abu">{{ $i->catatan }}
                                                @if ($url) · <a href="{{ $url }}" data-preview="{{ $i->dokumen }}" target="_blank" rel="noopener" class="text-url underline">{{ $i->dokumen }}@if ($i->halaman) hal. {{ $i->halaman }}@endif</a>
                                                @elseif ($i->dokumen) · {{ $i->dokumen }}@endif
                                            </p>
                                        @endif
                                        @if ($i->status === 'diterima')
                                            <p class="text-xs text-blue-800">Diterima: {{ $i->accepted_reason }}</p>
                                        @endif
                                    </div>
                                    @can('folders.analyze')
                                        @if ($i->status === 'kurang')
                                            <details class="shrink-0">
                                                <summary class="btn-ghost cursor-pointer text-sm">Terima</summary>
                                                <form method="POST" action="{{ route('folders.rubric.accept', [$folder, $i->kode]) }}" class="mt-2 w-64 space-y-2">
                                                    @csrf
                                                    <label for="alasan-{{ $i->kode }}" class="label">Alasan menerima</label>
                                                    <input id="alasan-{{ $i->kode }}" name="alasan" required minlength="3" maxlength="500" class="field">
                                                    <button type="submit" class="btn-secondary">Terima butir</button>
                                                </form>
                                            </details>
                                        @elseif ($i->status === 'diterima')
                                            <form method="POST" action="{{ route('folders.rubric.unaccept', [$folder, $i->kode]) }}" class="shrink-0">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-ghost text-sm">Batalkan</button>
                                            </form>
                                        @endif
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>

        @if ($saranOn)
            @include('folders.partials.suggestions')
        @endif

        {{-- Detail analisa: progres, ringkasan, galat --}}
        <div id="detail-folder" class="min-w-0">
            @include('folders.partials.detail', ['done' => $done ? (clone $done)->setAttribute('suggestions', []) : null])
        </div>

        </div>

        <div class="min-h-0 lg:overflow-y-auto lg:overscroll-contain" data-gulir-file>
        @if ($total)
            {{-- Accordion file: detail per dokumen --}}
            <section class="flex min-w-0 flex-col rounded-lg border border-gray-200 bg-white" aria-labelledby="judul-file">
                <div class="sticky top-0 z-10 shrink-0 rounded-t-lg border-b border-gray-200 bg-white px-4 py-3">
                    <div class="flex items-baseline justify-between gap-3">
                        <h2 id="judul-file" class="font-semibold text-arang">File ({{ $total }})</h2>
                        <p class="text-sm text-abu" data-docs-progress>{{ $selesai }} dari {{ $total }} file selesai</p>
                    </div>
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Kemajuan baca file"
                         aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $selesai }}" data-docs-bar>
                        <div class="h-full rounded-full bg-green-600 transition-[width] duration-200 motion-reduce:transition-none" style="width: {{ round($selesai / $total * 100) }}%"></div>
                    </div>
                </div>
                <div class="space-y-2 p-3" data-docs-list>
                    @foreach ($documents as $d)
                        @include('folders.partials.document')
                    @endforeach
                </div>
            </section>
        @endif
        </div>
        </div>
    </div>
</div>
</x-app-layout>
