<x-app-layout :title="$folder->nama_pasien ?: $folder->name">
    {{-- Pencarian kecil: kembali ke daftar dengan kata kunci --}}
    <form method="GET" action="{{ route('folders.index') }}" role="search" class="max-w-[692px]">
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
    @endphp

    {{-- Satu kolom: kepala → progres → rekomendasi keseluruhan → daftar file --}}
    <div class="mt-6 max-w-[960px] space-y-6" data-docs data-status-url="{{ route('folders.status', $folder) }}" data-active="{{ $berjalan ? '1' : '0' }}">
        <header class="flex flex-wrap items-start justify-between gap-4" data-kepala>
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
                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('folders.analyze', $folder) }}">
                            @csrf
                            <button type="submit" class="btn-primary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker) @if ($blocker) title="{{ $blocker }}" @endif>Analisa ulang dengan AI</button>
                        </form>
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

        @if ($total)
            <div>
                <p class="text-sm text-abu" data-docs-progress>{{ $selesai }} dari {{ $total }} file selesai</p>
                <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Kemajuan baca file"
                     aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $selesai }}" data-docs-bar>
                    <div class="h-full rounded-full bg-green-600 transition-[width] duration-200 motion-reduce:transition-none" style="width: {{ round($selesai / $total * 100) }}%"></div>
                </div>
            </div>
        @endif

        <div id="detail-folder">
            @include('folders.partials.detail')
        </div>

        @if ($total)
            <section aria-labelledby="judul-file">
                <h2 id="judul-file" class="font-semibold text-arang">File ({{ $total }})</h2>
                <div class="mt-3 space-y-2" data-docs-list>
                    @foreach ($documents as $d)
                        @include('folders.partials.document')
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
