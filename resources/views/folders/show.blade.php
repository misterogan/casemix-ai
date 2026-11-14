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

    {{-- Tahap 1: satu item accordion per file; diperbarui lewat polling tanpa memuat ulang halaman --}}
    @if ($documents->isNotEmpty())
        @php($selesai = $documents->reject(fn ($d) => $d->status->isActive())->count())
        @php($total = $documents->count())
        @php($tertahan = $documents->contains(fn ($d) => in_array($d->status->value, ['gagal', 'dibatalkan'], true)))
        @php($berjalan = (bool) $latest?->status?->isActive())
        <section class="mt-6 max-w-[692px]" aria-labelledby="judul-dokumen"
                 data-docs data-status-url="{{ route('folders.status', $folder) }}" data-active="{{ $berjalan ? '1' : '0' }}">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="judul-dokumen" class="font-semibold">File dalam folder</h2>
                <p class="text-sm text-abu" data-docs-progress>{{ $selesai }} dari {{ $total }} file selesai</p>
            </div>
            <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Kemajuan baca file"
                 aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $selesai }}" data-docs-bar>
                <div class="h-full rounded-full bg-green-600 transition-[width] duration-200 motion-reduce:transition-none" style="width: {{ $total ? round($selesai / $total * 100) : 0 }}%"></div>
            </div>

            <div class="mt-3 space-y-2" data-docs-list>
                @foreach ($documents as $d)
                    @include('folders.partials.document')
                @endforeach
            </div>

            @can('folders.analyze')
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('folders.resume', $folder) }}" data-docs-resume @unless ($tertahan) hidden @endunless>
                        @csrf
                        <button type="submit" class="btn-primary" @if ($berjalan && ! $blocker) data-lock @endif @disabled($berjalan || $blocker)>Lanjutkan dari dokumen yang gagal</button>
                    </form>
                    <form method="POST" action="{{ route('folders.process_all', $folder) }}" data-konfirmasi="Semua file akan dibaca ulang dan memakai token. Lanjutkan?">
                        @csrf
                        <button type="submit" class="btn-secondary" @if ($berjalan && ! $blocker) data-lock @endif @disabled($berjalan || $blocker)>Proses ulang semua file</button>
                    </form>
                </div>
            @endcan
        </section>
    @endif

    <div id="detail-folder" class="mt-6">
        @include('folders.partials.detail')
    </div>
</x-app-layout>
