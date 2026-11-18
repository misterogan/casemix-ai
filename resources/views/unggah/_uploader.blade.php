{{-- Komponen unggah: antrean file, progress per file (XHR), coba lagi per file. $folderId = folder tujuan (opsional). --}}
<div data-uploader data-store-url="{{ route('manual.store') }}" data-analyze-url="{{ route('manual.analyze', ['folder' => '__ID__']) }}"
     data-folder-id="{{ $folderId ?? '' }}" class="space-y-3">
    <div data-drop class="rounded-lg border-2 border-dashed border-gray-300 bg-gray-50 px-4 py-8 text-center text-sm text-abu">
        <p>Tarik dan lepas PDF/JPG/PNG/WEBP di sini</p>
        <label class="btn-secondary mt-3 inline-block cursor-pointer">
            Pilih file / kamera
            <input type="file" multiple accept="image/*,application/pdf" class="sr-only" data-input>
        </label>
        <p class="mt-2 text-xs">Maks 25 MB per file, 20 file per unggahan, 150 MB per folder. Foto HEIC: ubah ke JPG dulu.</p>
    </div>
    <ul class="space-y-2" data-list></ul>
    <template data-item-template>
        <li class="flex flex-wrap items-center gap-3 rounded-md border border-gray-200 bg-white p-2" data-item>
            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 text-xs font-semibold text-abu" data-thumb>PDF</div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-arang" data-name></p>
                <p class="text-xs text-abu" data-size></p>
                <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-200"><div class="h-full w-0 bg-green-600" data-bar></div></div>
                <p class="mt-1 text-xs text-red-700" data-error hidden></p>
            </div>
            <select class="rounded-md border-gray-300 text-sm" data-jenis aria-label="Jenis dokumen">
                <option value="">Jenis (opsional)</option>
                @foreach (\App\Models\ManualFile::JENIS as $k => $l)
                    <option value="{{ $k }}">{{ $l }}</option>
                @endforeach
            </select>
            <button type="button" class="btn-secondary" data-retry hidden>Coba lagi</button>
            <button type="button" class="text-sm text-red-700 underline" data-remove>Hapus</button>
        </li>
    </template>
    <p class="text-sm text-red-700" data-pesan hidden></p>
    <button type="button" class="btn-primary" data-submit disabled>Unggah dan analisa</button>
</div>
