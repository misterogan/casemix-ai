<x-app-layout title="Unggah manual">
@php
    $kunci = fn (string $f) => '<span class="hidden items-center gap-1 text-xs text-tinta-2" data-lock="' . $f . '"><svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Terkunci · <button type="button" class="text-brand underline" data-unlock="' . $f . '">Ubah</button></span>';
@endphp
<div class="mx-auto max-w-6xl px-4 pt-4 pb-0 lg:px-8 lg:pt-8">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold text-tinta">Unggah manual</h1>
            <p class="mt-1 text-sm text-abu">Dokumen dianalisa sama seperti folder FTP. Keputusan koding tetap pada koder/DPJP.</p>
        </div>
        <a href="{{ route('work-queue.index', ['sumber' => 'manual']) }}" class="text-sm text-brand hover:underline">Lihat riwayat unggahan</a>
    </header>

    <div data-uploader data-store-url="{{ route('manual.store') }}" data-analyze-url="{{ route('manual.analyze', ['folder' => '__ID__']) }}" data-folder-id="">
        <div class="mt-6 grid gap-6 lg:grid-cols-12">
            <x-panel step="1" title="Data pasien" class="lg:col-span-5 lg:self-start">
                <div class="space-y-4" data-manual-form data-lookup-url="{{ route('manual.lookup') }}">
                    <x-field name="no_sep" label="No. SEP" hint="Opsional, 19 karakter. Bila cocok dengan data BPJS, isian lain terisi otomatis.">
                        <x-slot:aside><span class="num text-xs text-abu" data-sep-count aria-live="polite">0/19</span></x-slot:aside>
                        <x-input name="no_sep" maxlength="19" class="font-mono tracking-wide" placeholder="0030R0011125V000123" autocomplete="off" data-field="no_sep" :hint="true" />
                        <div class="mt-2 rounded-[6px] border border-giok/25 bg-giok-muda px-3 py-2 text-sm text-giok-tua" data-vclaim hidden role="status"></div>
                        <div class="mt-2 rounded-[6px] border border-brand/25 bg-brand-soft px-3 py-2.5 text-sm text-tinta" data-existing hidden role="status">
                            <p>Folder untuk SEP ini sudah ada: <a class="font-medium text-brand underline" data-existing-link></a></p>
                            <button type="button" class="btn-secondary mt-2" data-use-existing>Tambahkan ke folder itu</button>
                        </div>
                    </x-field>
                    <x-field name="no_rm" label="No. RM" hint="Wajib bila No. SEP kosong.">
                        <x-slot:aside>{!! $kunci('no_rm') !!}</x-slot:aside>
                        <x-input name="no_rm" maxlength="30" class="num" data-field="no_rm" :hint="true" />
                    </x-field>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-field name="jenis_rawat" label="Jenis pelayanan">
                            <x-slot:aside>{!! $kunci('jenis_rawat') !!}</x-slot:aside>
                            <x-select name="jenis_rawat" data-field="jenis_rawat">
                                <option value="inap">Rawat inap</option>
                                <option value="jalan">Rawat jalan</option>
                            </x-select>
                        </x-field>
                        <x-field name="tgl_pulang" label="Tanggal pulang" hint="Opsional.">
                            <x-slot:aside>{!! $kunci('tgl_pulang') !!}</x-slot:aside>
                            <x-input type="date" name="tgl_pulang" data-field="tgl_pulang" :hint="true" />
                        </x-field>
                    </div>
                    <x-field name="catatan" label="Catatan" hint="Opsional.">
                        <x-textarea name="catatan" rows="3" maxlength="1000" data-field="catatan" :hint="true" />
                    </x-field>
                </div>
            </x-panel>

            <x-panel step="2" title="Dokumen" class="lg:col-span-7">
                @include('unggah._dropzone', ['uid' => 'unggah'])
            </x-panel>
        </div>

        {{-- Bilah aksi: menempel di bawah layar saat menggulir --}}
        <div class="sticky bottom-0 z-20 -mx-4 mt-6 border-t border-garis bg-white/95 px-4 py-3 backdrop-blur lg:-mx-8 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="flex items-center gap-2 text-sm text-tinta-2" data-status-bar aria-live="polite">Isi No. SEP atau No. RM</p>
                <button type="button" class="btn-primary" data-submit disabled>Unggah dan analisa</button>
            </div>
        </div>
    </div>
</div>
@vite('resources/js/manual-upload.js')
</x-app-layout>
