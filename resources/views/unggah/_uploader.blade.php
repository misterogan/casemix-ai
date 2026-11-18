{{-- Komponen unggah untuk folder yang sudah ada (panel "Dokumen tambahan"). --}}
<div data-uploader data-store-url="{{ route('manual.store') }}" data-analyze-url="{{ route('manual.analyze', ['folder' => '__ID__']) }}"
     data-folder-id="{{ $folderId ?? '' }}">
    @include('unggah._dropzone', ['uid' => 'tambah-' . ($folderId ?? 'baru')])
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <p class="flex items-center gap-2 text-sm text-tinta-2" data-status-bar aria-live="polite">Tambahkan minimal 1 file</p>
        <button type="button" class="btn-primary" data-submit disabled>Unggah dan analisa</button>
    </div>
</div>
