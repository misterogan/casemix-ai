{{-- Dokumen tambahan (unggahan manual): dianalisa bersama file FTP folder ini. --}}
<section class="panel" aria-labelledby="judul-manual" data-dokumen-tambahan>
    <div class="flex items-center justify-between gap-3">
        <h2 id="judul-manual" class="font-semibold text-arang">Dokumen tambahan ({{ $manualFiles->count() }})</h2>
        <button type="button" class="btn-secondary" onclick="this.closest('[data-dokumen-tambahan]').querySelector('[data-tambah]').hidden = false; this.hidden = true">Tambah dokumen</button>
    </div>
    @if ($manualFiles->isNotEmpty())
        <ul class="mt-3 divide-y divide-gray-100 text-sm">
            @foreach ($manualFiles as $m)
                <li class="flex flex-wrap items-start justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <a href="{{ route('manual.show', [$folder, $m]) }}" target="_blank" rel="noopener" class="break-all text-biru-aktif hover:underline">{{ $m->nama_asli }}</a>
                        <p class="text-xs text-abu">{{ $m->jenisLabel() }} · {{ \Illuminate\Support\Number::fileSize($m->ukuran, 1) }} · {{ $m->uploader?->name ?? '—' }} · {{ $m->created_at->translatedFormat('d M Y H:i') }}</p>
                    </div>
                    <details>
                        <summary class="cursor-pointer text-red-700 underline">Hapus</summary>
                        <form method="POST" action="{{ route('manual.destroy', [$folder, $m]) }}" class="mt-2 flex gap-2">
                            @csrf @method('DELETE')
                            <input name="alasan" required minlength="5" maxlength="500" placeholder="Alasan hapus (wajib)" class="rounded-md border-gray-300 text-sm">
                            <button class="btn-secondary">Hapus</button>
                        </form>
                    </details>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-2 text-sm text-abu">Belum ada dokumen tambahan.</p>
    @endif
    <div class="mt-4" data-tambah hidden>
        @include('unggah._uploader', ['folderId' => $folder->id])
    </div>
</section>
