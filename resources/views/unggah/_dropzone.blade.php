{{-- Dropzone + daftar file (dipakai halaman /unggah dan panel "Dokumen tambahan"). Selector data-* dipakai manual-upload.js. --}}
@php($uid = $uid ?? 'unggah')
<div data-drop tabindex="0" role="button" aria-describedby="{{ $uid }}-batas"
     class="dropzone group flex min-h-[200px] cursor-pointer flex-col items-center justify-center gap-3 rounded-[10px] border-2 border-dashed border-garis-tua bg-white px-4 py-8 text-center transition-colors duration-150 hover:border-brand/60 focus-visible:border-brand focus-visible:ring-[3px] focus-visible:ring-brand/15 focus-visible:outline-none">
    <span class="inline-flex size-12 items-center justify-center rounded-full bg-brand-soft text-brand" aria-hidden="true">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>
    </span>
    <p class="text-sm font-medium text-tinta">Tarik file ke sini atau pilih file</p>
    <div class="flex flex-wrap items-center justify-center gap-2">
        <label class="btn-secondary cursor-pointer" data-pilih>
            Pilih file
            <input id="{{ $uid }}-file" type="file" multiple accept="image/*,application/pdf" class="sr-only" data-input>
        </label>
        <label class="btn-ghost hidden cursor-pointer touch-only" data-pilih>
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/></svg>
            Ambil foto
            <input type="file" accept="image/*" capture="environment" class="sr-only" data-input>
        </label>
    </div>
    <p id="{{ $uid }}-batas" class="text-xs text-abu">PDF, JPG, PNG, WEBP · maks 25 MB per file, 20 file per unggahan, 150 MB per folder · foto HEIC: ubah ke JPG dulu</p>
</div>

<p class="mt-4 text-sm font-medium text-tinta" data-ringkasan hidden aria-live="polite"></p>
<ul class="mt-2 space-y-2" data-list aria-label="File yang akan diunggah"></ul>
<p class="mt-2 text-sm text-kritis" data-pesan hidden role="alert"></p>

<template data-item-template>
    <li class="relative overflow-hidden rounded-[6px] border border-garis bg-white" data-item>
        <div class="flex flex-wrap items-center gap-3 p-2.5 sm:flex-nowrap">
            <div class="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-[6px] bg-kritis-muda text-kritis" data-thumb aria-hidden="true">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15h6"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-tinta" data-name></p>
                <p class="text-xs text-abu"><span data-size></span> · <span data-status>Menunggu</span></p>
                <p class="mt-0.5 text-xs text-kritis" data-error hidden></p>
            </div>
            <div class="relative w-40 shrink-0">
                <select class="field h-8 py-1 pr-8 text-xs" data-jenis aria-label="Jenis dokumen">
                    <option value="">Jenis (opsional)</option>
                    @foreach (\App\Models\ManualFile::JENIS as $k => $l)
                        <option value="{{ $k }}">{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <button type="button" class="btn-secondary h-8 px-3 text-xs" data-retry hidden>Coba lagi</button>
            <button type="button" class="btn-ghost size-8 px-0" data-remove aria-label="Hapus dari antrean">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="absolute inset-x-0 bottom-0 h-1 bg-abu-muda"><div class="h-full w-0 bg-brand transition-[width] duration-150" data-bar></div></div>
    </li>
</template>
