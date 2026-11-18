{{-- Penampil dokumen: ≥1280px kolom kanan sticky; <1280px drawer dari kanan (dibuka saat bukti diklik). --}}
<div class="vf-latar" data-penampil-latar hidden></div>
<aside id="penampil" class="vf-penampil" tabindex="-1" aria-labelledby="judul-penampil" data-penampil data-awal="{{ $awal }}">
    <h2 id="judul-penampil" class="sr-only">Penampil dokumen</h2>
    <div class="flex items-center gap-2 border-b border-gray-200 px-2">
        <div class="flex min-w-0 flex-1 gap-1 overflow-x-auto py-2" role="tablist" aria-label="File" data-penampil-tab>
            @forelse ($berkas as $i => $b)
                <button type="button" role="tab" id="tab-file-{{ $i }}" aria-selected="false" aria-controls="penampil-isi" tabindex="-1"
                        class="vf-tab" data-tab-file="{{ $i }}" data-nama="{{ $b['nama'] }}" data-url="{{ $b['url'] }}">{{ $b['nama'] }}</button>
            @empty
                <p class="px-2 text-[13px] text-gray-500">Tidak ada file.</p>
            @endforelse
        </div>
        <button type="button" class="btn-ghost shrink-0 xl:hidden" data-penampil-tutup aria-label="Tutup penampil">✕</button>
    </div>
    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-3 py-2 text-[13px] text-gray-700">
        <button type="button" class="btn-ghost btn-sm" data-hal-sebelum aria-label="Halaman sebelumnya">‹</button>
        <span class="num min-w-[5.5rem] text-center" data-hal-info aria-live="polite">—</span>
        <button type="button" class="btn-ghost btn-sm" data-hal-berikut aria-label="Halaman berikutnya">›</button>
        <label class="ml-2 inline-flex items-center gap-1">Zoom
            <select class="field h-8 py-0 text-[13px]" data-zoom>
                <option value="lebar">Pas lebar</option>
                <option value="1">100%</option>
                <option value="1.25">125%</option>
            </select>
        </label>
        <a href="#" target="_blank" rel="noopener" class="ml-auto text-url underline" data-penampil-tab-baru>Buka di tab baru ↗</a>
    </div>
    <div id="penampil-isi" role="tabpanel" class="min-h-0 flex-1 overflow-auto bg-gray-100 p-2" data-penampil-isi>
        <p class="p-4 text-[13px] text-gray-500">Pilih file atau klik bukti untuk menampilkan dokumen.</p>
    </div>
</aside>
