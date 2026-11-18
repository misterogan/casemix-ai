{{-- Dokumen tambahan (unggahan manual): dianalisa bersama file FTP folder ini. --}}
@php
    $svg = fn (string $d, string $c = 'size-5') => new \Illuminate\Support\HtmlString('<svg class="' . $c . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>');
    $klip = '<path d="m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l9.2-9.2a4 4 0 0 1 5.7 5.7l-9.2 9.2a2 2 0 0 1-2.8-2.8l8.5-8.5"/>';
    $filePlus = '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 18v-6M9 15h6"/>';
@endphp
<x-card lapang aria-labelledby="judul-manual" data-dokumen-tambahan>
    <x-slot:header>
        <div class="flex min-w-0 items-center gap-2">
            <span class="text-gray-500">{{ $svg($klip) }}</span>
            <h2 id="judul-manual" class="text-base font-semibold text-gray-800 dark:text-white/90">Dokumen tambahan</h2>
            <x-badge variant="gray" data-jumlah-manual>{{ $manualFiles->count() }}</x-badge>
        </div>
    </x-slot:header>
    <x-slot:actions>
        <button type="button" class="btn-secondary btn-sm h-9" data-buka-tambah
                onclick="this.closest('[data-dokumen-tambahan]').querySelector('[data-tambah]').hidden = false; this.hidden = true">
            {!! $svg('<path d="M12 5v14M5 12h14"/>', 'size-4') !!}Tambah dokumen</button>
    </x-slot:actions>

    @if ($manualFiles->isNotEmpty())
        <ul class="divide-y divide-gray-100 dark:divide-gray-800" data-daftar-manual>
            @foreach ($manualFiles as $m)
                @php $gambar = str_starts_with((string) $m->mime, 'image/'); @endphp
                <li class="flex items-center gap-3 py-3 first:pt-0">
                    <span class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-800">
                        @if ($gambar)
                            <img src="{{ route('manual.show', [$folder, $m]) }}" alt="" class="size-10 object-cover" loading="lazy">
                        @else
                            <span class="text-[10px] font-semibold text-error-600">PDF</span>
                        @endif
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 items-center gap-2">
                            <a href="{{ route('manual.show', [$folder, $m]) }}" target="_blank" rel="noopener" class="truncate text-sm font-medium text-gray-800 hover:underline dark:text-white/90" title="{{ $m->nama_asli }}">{{ $m->nama_asli }}</a>
                            <x-badge variant="gray" class="shrink-0">{{ $m->jenisLabel() }}</x-badge>
                        </div>
                        <p class="mt-0.5 truncate text-xs text-gray-500">{{ \Illuminate\Support\Number::fileSize($m->ukuran, 1) }} · {{ $m->uploader?->name ?? '—' }} · <time datetime="{{ $m->created_at->toIso8601String() }}" title="{{ $m->created_at->translatedFormat('d M Y H:i') }}">{{ $m->created_at->diffForHumans() }}</time></p>
                    </div>
                    <x-dropdown width="w-72">
                        <a href="{{ route('manual.show', [$folder, $m]) }}" target="_blank" rel="noopener" class="dropdown-item">Lihat</a>
                        <details class="px-2 py-1">
                            <summary class="dropdown-item cursor-pointer list-none text-error-600">Hapus</summary>
                            <form method="POST" action="{{ route('manual.destroy', [$folder, $m]) }}" class="mt-2 flex gap-2 pb-1">
                                @csrf @method('DELETE')
                                <label for="alasan-{{ $m->id }}" class="sr-only">Alasan hapus</label>
                                <x-input id="alasan-{{ $m->id }}" name="alasan" required minlength="5" maxlength="500" placeholder="Alasan hapus (wajib)" />
                                <button class="btn-secondary btn-sm">Hapus</button>
                            </form>
                        </details>
                    </x-dropdown>
                </li>
            @endforeach
        </ul>
        <div role="button" tabindex="0" data-manual-drop
             class="mt-3 flex h-14 cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 text-sm text-gray-500 transition-colors hover:border-brand-500 data-[drag]:border-solid data-[drag]:border-brand-500 data-[drag]:bg-brand-50 dark:border-gray-700">
            {{ $svg($filePlus, 'size-4') }}Tarik file ke sini untuk menambah
        </div>
    @else
        <div role="button" tabindex="0" data-manual-drop data-manual-kosong
             class="flex cursor-pointer flex-col items-center rounded-xl border border-dashed border-gray-300 p-6 text-center transition-colors hover:border-brand-500 data-[drag]:border-solid data-[drag]:border-brand-500 data-[drag]:bg-brand-50 dark:border-gray-700">
            <span class="grid size-12 place-items-center rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800">{{ $svg($filePlus) }}</span>
            <p class="mt-3 text-sm font-medium text-gray-800 dark:text-white/90">Belum ada dokumen tambahan</p>
            <p class="mx-auto mt-1 max-w-sm text-[13px] text-gray-500">Tambahkan dokumen yang tidak ada di folder FTP, misalnya hasil lab atau resume yang tertinggal.</p>
            <span class="mt-2 text-sm font-medium text-brand-600 hover:underline">Pilih file</span>
        </div>
    @endif
    <div class="mt-4" data-tambah hidden>
        @include('unggah._uploader', ['folderId' => $folder->id])
    </div>
</x-card>
