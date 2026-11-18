<x-app-layout :title="$dokumen->judul">
    <x-page-header :title="$dokumen->judul" :subtitle="$dokumen->nomor_kmk . ' · ' . $dokumen->tahun . ' · berlaku ' . $dokumen->tanggal_berlaku?->translatedFormat('d M Y') . ' · ' . (\App\Models\PnpkDokumen::PROSES[$dokumen->status_proses] ?? $dokumen->status_proses)" :back="route('acuan.pnpk.index')">
        <x-slot:actions>
            <x-badge :variant="\App\Models\PnpkDokumen::STATUS_TONE[$dokumen->status] ?? 'gray'">{{ \App\Models\PnpkDokumen::STATUS[$dokumen->status] ?? $dokumen->status }}</x-badge>
            <a href="{{ route('acuan.pnpk.edit', $dokumen) }}" class="btn-secondary">Ubah metadata</a>
            @if ($bolehTinjau && $dokumen->status_proses !== 'selesai')
                <form method="POST" action="{{ route('acuan.pnpk.selesai', $dokumen) }}" @if ($alasanBelumSelesai) title="{{ $alasanBelumSelesai }}" @endif>@csrf<button class="btn-primary disabled:cursor-not-allowed disabled:opacity-50" data-selesai @disabled($alasanBelumSelesai) @if ($alasanBelumSelesai) title="{{ $alasanBelumSelesai }}" @endif>Selesai ditinjau</button></form>
            @endif
        </x-slot:actions>
    </x-page-header>
    @include('acuan._tabs')

    @if ($dokumen->penggantiDokumen)
        <p class="mb-3 text-sm text-tinta-3">Digantikan oleh <a class="text-url" href="{{ route('acuan.pnpk.show', $dokumen->penggantiDokumen) }}">{{ $dokumen->penggantiDokumen->judul }}</a>.</p>
    @endif
    @if ($dokumen->status_proses === 'gagal' && $dokumen->error)
        <p class="mb-3 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600">{{ $dokumen->error }}</p>
    @endif
    @unless ($bolehTinjau)
        <p class="mb-3 text-sm text-tinta-3">Hanya-baca: peninjauan butir dan ICD dilakukan Koordinator.</p>
    @endunless

    <div class="grid gap-4 lg:grid-cols-2" data-pnpk-review data-pdf-url="{{ route('acuan.pnpk.pdf', $dokumen) }}" data-hal="{{ (int) request('hal', 1) }}">
        <div class="space-y-4">
            <section class="panel">
                <div class="panel-body">
                    <h2 class="font-semibold text-arang">ICD-10 yang dicakup</h2>
                    <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                        @forelse ($dokumen->icd as $i)
                            <li class="flex items-center gap-1 rounded-lg border border-garis px-2 py-1" data-icd="{{ $i->kode }}">
                                <span class="num font-medium">{{ $i->kode }}</span>
                                <x-badge :variant="['disetujui' => 'success', 'ditolak' => 'error'][$i->status] ?? 'info'">{{ ucfirst($i->status) }}</x-badge>
                                @if ($i->status === 'disetujui' && ! $i->diperiksa_manusia)<x-badge variant="warning" data-otomatis>Otomatis</x-badge>
                                    @if ($bolehTinjau)<form method="POST" action="{{ route('acuan.pnpk.icd', [$dokumen, $i]) }}">@csrf<input type="hidden" name="aksi" value="konfirmasi"><button class="btn-ghost text-xs" data-konfirmasi>Konfirmasi</button></form>@endif
                                @endif
                                <span class="text-xs text-tinta-3">{{ ['ai' => 'AI', 'teks' => 'teks dokumen'][$i->sumber] ?? 'manual' }}@if ($i->alasan_tolak) · {{ $i->alasan_tolak }}@endif</span>
                                @if ($bolehTinjau && $i->status === 'usulan')
                                    <form method="POST" action="{{ route('acuan.pnpk.icd', [$dokumen, $i]) }}">@csrf<input type="hidden" name="aksi" value="setujui"><button class="btn-ghost text-xs">Setujui</button></form>
                                    <form method="POST" action="{{ route('acuan.pnpk.icd', [$dokumen, $i]) }}">@csrf<input type="hidden" name="aksi" value="tolak"><button class="btn-ghost text-xs">Tolak</button></form>
                                @endif
                            </li>
                        @empty
                            <li class="text-tinta-3">Belum ada.</li>
                        @endforelse
                    </ul>
                    @if ($bolehTinjau)
                        <form method="POST" action="{{ route('acuan.pnpk.icd.tambah', $dokumen) }}" class="mt-3 flex gap-2">
                            @csrf
                            <input name="kode" class="field num w-32" placeholder="mis. J18" maxlength="12" aria-label="Tambah ICD manual">
                            <button class="btn-secondary">Tambah ICD</button>
                        </form>
                        @error('kode')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                    @endif
                    @error('aksi')<p class="mt-1 text-xs text-error-600">{{ $message }}</p>@enderror
                </div>
            </section>

            @foreach (\App\Models\PnpkButir::JENIS as $jenis => $label)
                @php $items = $perJenis->get($jenis, collect()); @endphp
                @continue($items->isEmpty())
                <section class="panel" data-jenis="{{ $jenis }}">
                    <div class="flex items-center justify-between gap-2 border-b border-garis px-5 py-3">
                        <h2 class="font-semibold text-arang">{{ $label }} <span class="text-xs font-normal text-tinta-3">({{ $items->count() }})</span></h2>
                        @if ($bolehTinjau && $items->where('status', 'usulan')->where('cocok_kutipan', true)->isNotEmpty())
                            <form method="POST" action="{{ route('acuan.pnpk.butir.massal', $dokumen) }}">@csrf<input type="hidden" name="jenis" value="{{ $jenis }}">
                                <button class="btn-ghost text-xs">Setujui semua yang cocok</button></form>
                        @endif
                        @if ($bolehTinjau && $items->where('status', 'disetujui')->where('diperiksa_manusia', false)->isNotEmpty())
                            <form method="POST" action="{{ route('acuan.pnpk.butir.massal', $dokumen) }}">@csrf<input type="hidden" name="jenis" value="{{ $jenis }}"><input type="hidden" name="aksi" value="konfirmasi">
                                <button class="btn-ghost text-xs" data-konfirmasi-semua>Konfirmasi semua di jenis ini</button></form>
                        @endif
                    </div>
                    <ul class="divide-y divide-garis">
                        @foreach ($items as $b)
                            <li class="space-y-2 px-5 py-3 text-sm" data-butir="{{ $b->id }}">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :variant="['disetujui' => 'success', 'ditolak' => 'error'][$b->status] ?? 'info'">{{ ucfirst($b->status) }}</x-badge>
                                    @if ($b->status === 'disetujui' && ! $b->diperiksa_manusia)<x-badge variant="warning" data-otomatis>Otomatis</x-badge>
                                        @if ($bolehTinjau)<form method="POST" action="{{ route('acuan.pnpk.butir', [$dokumen, $b]) }}">@csrf<input type="hidden" name="aksi" value="konfirmasi"><button class="btn-ghost text-xs" data-konfirmasi>Konfirmasi</button></form>@endif
                                    @endif
                                    <x-badge :variant="$b->cocok_kutipan ? 'success' : 'error'">{{ $b->cocok_kutipan ? 'Kutipan cocok' : 'Kutipan tidak cocok' }}</x-badge>
                                    <button type="button" class="text-xs text-url" data-goto="{{ $b->halamanBuka() }}" @if ($b->halaman_pdf && $b->halaman_pdf !== $b->halaman) title="Label AI: hal. {{ $b->halaman }}" @endif>hal. {{ $b->halamanBuka() }}</button>
                                    @if ($b->level_bukti)<span class="text-xs text-tinta-3">Level bukti {{ $b->level_bukti }}</span>@endif
                                </div>
                                <p class="text-arang">{{ $b->ringkasan }}</p>
                                <blockquote class="border-l-4 border-garis pl-3 text-xs italic text-tinta-3">“{{ $b->kutipan }}”</blockquote>
                                @if ($b->alasan_tolak)<p class="text-xs text-error-600">{{ $b->alasan_tolak }}</p>@endif
                                @if ($bolehTinjau)
                                    <div class="flex flex-wrap gap-2">
                                        @if ($b->status !== 'disetujui' && $b->cocok_kutipan)
                                            <form method="POST" action="{{ route('acuan.pnpk.butir', [$dokumen, $b]) }}">@csrf<input type="hidden" name="aksi" value="setujui"><button class="btn-secondary text-xs">Setujui</button></form>
                                        @endif
                                        @if ($b->status !== 'ditolak')
                                            <form method="POST" action="{{ route('acuan.pnpk.butir', [$dokumen, $b]) }}" class="flex gap-1">@csrf<input type="hidden" name="aksi" value="tolak">
                                                <input name="alasan" required maxlength="255" class="field text-xs" placeholder="Alasan tolak" aria-label="Alasan tolak"><button class="btn-ghost text-xs">Tolak</button></form>
                                        @endif
                                        <details>
                                            <summary class="btn-ghost cursor-pointer text-xs">Ubah ringkasan</summary>
                                            <form method="POST" action="{{ route('acuan.pnpk.butir', [$dokumen, $b]) }}" class="mt-2 space-y-2">@csrf<input type="hidden" name="aksi" value="ringkasan">
                                                <textarea name="ringkasan" rows="3" maxlength="300" required class="field text-sm" aria-label="Ringkasan">{{ $b->ringkasan }}</textarea>
                                                <button class="btn-secondary text-xs">Simpan ringkasan</button></form>
                                        </details>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
            @if ($perJenis->isEmpty())
                <x-empty-state title="Belum ada usulan" text="Usulan muncul setelah teks diekstrak dan AI selesai menyusun butir."/>
            @endif
        </div>

        <aside class="panel lg:sticky lg:top-4 lg:self-start">
            <div class="flex items-center justify-between gap-2 border-b border-garis px-4 py-2 text-sm">
                <button type="button" class="btn-ghost" data-prev aria-label="Halaman sebelumnya">‹</button>
                <span class="num" data-info>hal. –</span>
                <button type="button" class="btn-ghost" data-next aria-label="Halaman berikutnya">›</button>
                <a class="text-xs text-url" href="{{ route('acuan.pnpk.pdf', $dokumen) }}" target="_blank" rel="noopener">Buka PDF</a>
            </div>
            <div class="max-h-[80vh] overflow-auto p-2"><canvas data-canvas class="mx-auto max-w-full"></canvas></div>
        </aside>
    </div>
    @vite('resources/js/pnpk-review.js')
</x-app-layout>
