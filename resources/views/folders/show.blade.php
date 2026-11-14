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

    {{-- Tahap 1: hasil baca per dokumen, tampil bertahap --}}
    @if ($documents->isNotEmpty())
        @php($selesai = $documents->filter->isDone()->count())
        @php($berjalan = (bool) $latest?->status?->isActive())
        <section class="mt-6 max-w-[692px]" aria-labelledby="judul-dokumen">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="judul-dokumen" class="font-semibold">Dokumen</h2>
                <p class="text-sm text-abu">{{ $selesai }} dari {{ $documents->count() }} dokumen selesai</p>
            </div>
            @if ($berjalan)
                <div hidden data-poll="{{ route('folders.status', $folder) }}" data-poll-done="{{ $selesai }}"></div>
            @endif
            <ul class="mt-3 divide-y divide-gray-200 rounded-lg border border-gray-200">
                @foreach ($documents as $d)
                    <li class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            @switch($d->status->value)
                                @case('selesai')
                                    <span class="text-green-700" aria-label="Selesai">✓</span>
                                    @break
                                @case('gagal')
                                    <span class="text-red-700" aria-label="Gagal">✕</span>
                                    @break
                                @case('berjalan')
                                    <svg class="size-4 animate-spin text-abu" viewBox="0 0 24 24" fill="none" aria-label="Sedang dibaca"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                                    @break
                                @default
                                    <span class="text-abu" aria-label="Menunggu">•</span>
                            @endswitch
                            @if (isset($linkable[$d->name]))
                                <a href="{{ route('folders.file', [$folder, $linkable[$d->name]]) }}" target="_blank" rel="noopener" class="font-medium hover:underline">{{ $d->name }}</a>
                            @else
                                <span class="font-medium">{{ $d->name }}</span>
                            @endif
                            <span class="ml-auto text-xs text-abu">
                                @switch($d->status->value)
                                    @case('selesai') {{ $d->reused ? 'tidak berubah' : 'selesai' }} @break
                                    @case('gagal') gagal @break
                                    @case('berjalan') sedang dibaca @break
                                    @default menunggu
                                @endswitch
                            </span>
                        </div>

                        @if ($d->status->value === 'selesai' && $d->findings)
                            <ul class="mt-2 space-y-1 pl-6 text-sm">
                                @foreach ($d->findings as $f)
                                    <li>
                                        <span class="{{ $f['tingkat'] === 'kritis' ? 'text-red-700' : 'text-amber-700' }} font-medium">{{ ucfirst($f['tingkat']) }}:</span>
                                        {{ $f['judul'] }}@if ($f['halaman']) <span class="text-abu">(hal. {{ $f['halaman'] }})</span>@endif
                                        @if ($f['uraian'])<span class="block text-abu">{{ $f['uraian'] }}</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @elseif ($d->status->value === 'selesai' && $d->error)
                            <p class="mt-1 pl-6 text-sm text-abu">{{ $d->error }}</p>
                        @elseif ($d->status->value === 'gagal')
                            <p class="mt-1 pl-6 text-sm text-red-700">{{ $d->error }}</p>
                            @can('folders.analyze')
                                @unless ($berjalan)
                                    <form method="POST" action="{{ route('folders.document.retry', [$folder, $d]) }}" class="mt-2 pl-6">
                                        @csrf
                                        <button type="submit" class="btn-secondary text-sm">Baca ulang dokumen ini</button>
                                    </form>
                                @endunless
                            @endcan
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <div id="detail-folder" class="mt-6">
        @include('folders.partials.detail')
    </div>
</x-app-layout>
