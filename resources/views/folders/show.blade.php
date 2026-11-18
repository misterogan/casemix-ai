<x-app-layout :title="$folder->nama_pasien ?: $folder->name">
<div class="vf-halaman">

    <p class="sr-only" aria-live="polite" data-kabar></p>

    @php
        $berjalan = (bool) $latest?->status?->isActive();
        $selesai = $documents->reject(fn ($d) => $d->status->isActive())->count();
        $total = $documents->count();
        $kunci = $berjalan && ! $blocker; // tombol yang dibuka JS saat analisa selesai
        $siap = $folder->ready();
        $acuanPerButir = \App\Models\Regulation::byRubric();
        $menunggu = $saranOn ? $suggestions->whereIn('status', ['baru', 'ditanyakan'])->count() : 0;
        $tautan = fn (?string $dok, ?int $hal) => $dok !== null && isset($linkable[$dok]) ? route('folders.file', [$folder, $linkable[$dok]]) . ($hal ? '#page=' . $hal : '') : null;
        $koordinator = auth()->user()->hasRole('koordinator');
        $jenis = \App\Support\Eklaim::JENIS_RAWAT[$folder->jenis_rawat] ?? 'Jenis rawat tidak terbaca';
        $kurang = $rubric->flatten()->filter(fn ($i) => $i->status === 'kurang' && $i->required())->values();
        $temuan = $temuan ?? collect();
        $dicek = $dicek ?? collect();
        $jumlahDicek = $temuan->filter(fn ($f) => $dicek->has($f['key']))->count();
        $bisaAnalisa = auth()->user()->can('folders.analyze') && (! $folder->missing_at || $hasCopy);
        $tampilAnalisa = $bisaAnalisa && ! ($siap === true && ! $changed && ! $berjalan);
        // Penampil: semua file folder; bawaan resume medis bila ada
        $berkas = collect($folder->files ?? [])->values()->map(fn ($f, $i) => ['nama' => $f['name'] ?? ('File ' . ($i + 1)), 'url' => route('folders.file', [$folder, $i])]);
        // Dokumen acuan yang dirujuk panel Dasar koding / Dasar sanggahan ikut dibuka di penampil
        $dasarKoding = $dasarKoding ?? collect();
        $sanggahan = $sanggahan ?? null;
        $berkas = $berkas->concat($dasarKoding->flatMap(fn ($x) => $x['butir'])->concat($sanggahan['semua'] ?? [])
            ->map(fn ($b) => $b->dokumen)->unique('id')->map(fn ($d) => ['nama' => $d->namaPenampil(), 'url' => route('acuan.pnpk.pdf', $d)]))->values();
        $resume = $documents->first(fn ($d) => $d->doc_type === 'resume_medis')?->name;
        $awal = $berkas->search(fn ($b) => $resume ? $b['nama'] === $resume : stripos($b['nama'], 'resume') !== false);
        $awal = $awal === false ? 0 : $awal;
        $bagian = array_filter([
            'ringkasan' => 'Ringkasan',
            'kode' => $kodeOn || $koordinator ? 'Diagnosis & kode' : null,
            'kelengkapan' => 'Kelengkapan',
            'temuan' => 'Temuan (' . $temuan->count() . ')',
            'pnpk' => $koordinator ? 'Acuan PNPK' : null,
            'bpjs' => $bpjs ? 'Status BPJS' : null,
            'sanggahan' => $sanggahan ? 'Dasar sanggahan' : null,
            'riwayat' => $bpjs && ! $bpjs['tanpa_sep'] ? 'Riwayat pelayanan' : null,
            'tambahan' => $koordinator ? 'Dokumen tambahan' : null,
            'file' => 'File (' . $total . ')',
        ]);
    @endphp

    <div class="vf-grid" data-docs data-muat-ulang="1" data-status-url="{{ route('folders.status', $folder) }}" data-active="{{ $berjalan ? '1' : '0' }}"
         data-folder-page data-cek-url="{{ route('folders.temuan_cek.store', $folder) }}">
        <div class="vf-konten min-w-0 space-y-4">
            <header class="flex flex-wrap items-start justify-between gap-4" data-kepala>
                <div class="min-w-0">
                    <p class="truncate text-[13px] text-gray-500">{{ $folder->isManual() ? 'Unggah manual' : 'FTP Enigma' }} › {{ $folder->name }}</p>
                    <h1 class="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90" data-judul-berkas>{{ $folder->nama_pasien ?: $folder->name }}
                        @if ($folder->isManual())<span class="ml-2 rounded-full bg-purple-100 px-2 py-0.5 align-middle text-xs font-medium text-purple-800">Manual</span>@endif
                    </h1>
                    <p class="num mt-1 text-[13px] text-gray-500">
                        RM {{ $folder->no_rm ?: '—' }} · SEP {{ $folder->no_sep ?: '—' }} · {{ $jenis }} · {{ $folder->file_count }} dokumen
                        @if ($folder->files_modified_at) · diperbarui di FTP {{ $folder->files_modified_at->translatedFormat('d M Y H:i') }}@endif
                    </p>
                    @if ($menunggu)
                        <p class="mt-1 text-sm font-medium text-purple-800">Saran DPJP: {{ $menunggu }} menunggu tindak lanjut</p>
                    @endif
                </div>
                @if ($bisaAnalisa)
                    <div class="flex flex-wrap gap-2">
                        {{-- Siap diajukan: analisa ulang disembunyikan kecuali ada file berubah --}}
                        @if ($tampilAnalisa)
                            <form method="POST" action="{{ route('folders.analyze', $folder) }}">
                                @csrf
                                <button type="submit" class="btn-primary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker) @if ($blocker) title="{{ $blocker }}" @endif>Analisa ulang dengan AI</button>
                            </form>
                        @endif
                        @if ($total)
                            <form method="POST" action="{{ route('folders.process_all', $folder) }}" data-konfirmasi="Semua file akan dibaca ulang dan memakai token. Lanjutkan?">
                                @csrf
                                <button type="submit" class="btn-secondary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>Proses ulang semua file</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('folders.resynthesize', $folder) }}" data-resynth{{ $canResynth ? '' : ' hidden' }}>
                            @csrf
                            <button type="submit" class="btn-secondary" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>Ulangi rekomendasi keseluruhan</button>
                        </form>
                    </div>
                @endif
            </header>

            {{-- Bilah sticky: kepala ringkas (muncul setelah judul lewat) + navigasi bagian --}}
            <div class="vf-bilah" data-bilah>
                <div class="flex h-16 items-center gap-3 border-b border-gray-200 px-1 py-2" data-kepala-ringkas hidden>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[15px] font-semibold text-gray-800 dark:text-white/90">{{ $folder->nama_pasien ?: $folder->name }}</p>
                        <p class="num truncate text-[13px] text-gray-500">RM {{ $folder->no_rm ?: '—' }} · SEP {{ $folder->no_sep ?: '—' }} · {{ $jenis }}</p>
                    </div>
                    @if ($siap !== null)
                        <span @class(['chip shrink-0', 'chip-sesuai' => $siap, 'chip-kritis' => ! $siap])>{{ $siap ? 'Siap' : 'Belum siap' }}</span>
                    @endif
                    @if ($tampilAnalisa)
                        <form method="POST" action="{{ route('folders.analyze', $folder) }}" class="shrink-0">
                            @csrf
                            <button type="submit" class="btn-primary btn-sm" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>Analisa ulang</button>
                        </form>
                    @endif
                </div>
                <nav aria-label="Bagian halaman" class="flex gap-2 overflow-x-auto py-2" data-nav-bagian>
                    @foreach ($bagian as $id => $label)
                        <a href="#bagian-{{ $id }}" class="vf-chip" data-lompat data-nav="{{ $id }}">{{ $label }}</a>
                    @endforeach
                </nav>
            </div>

            {{-- Spanduk status: tiap butir wajib yang kurang + tautan ke bagiannya --}}
            @if ($siap !== null)
                <div @class(['rounded-2xl border p-5', 'border-green-300 bg-green-50' => $siap, 'border-red-300 bg-red-50' => ! $siap]) data-status-folder role="status">
                    <p @class(['text-lg font-semibold', 'text-green-800' => $siap, 'text-red-800' => ! $siap])>
                        {{ $siap ? 'Siap diajukan' : 'Belum siap · ' . $folder->rubric_kurang . ' butir kurang' }}
                    </p>
                    @if ($siap)
                        <p class="mt-1 text-[15px] text-green-900">Semua butir wajib lengkap.@if ($temuan->isNotEmpty()) {{ $jumlahDicek }} dari {{ $temuan->count() }} temuan sudah dicek.@endif</p>
                    @elseif ($kurang->isNotEmpty())
                        <ul class="mt-3 space-y-2">
                            @foreach ($kurang as $i)
                                <li class="flex items-start gap-3 text-[15px]" data-kurang="{{ $i->kode }}">
                                    <svg class="mt-1 size-4 shrink-0 text-red-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
                                    <p class="min-w-0 flex-1 text-red-900"><strong class="font-semibold">{{ $i->label() }}</strong>: {{ \Illuminate\Support\Str::limit($i->catatan ?: 'belum terpenuhi.', 160) }}</p>
                                    <a href="#butir-{{ $i->kode }}" class="shrink-0 font-medium text-red-800 underline" data-lompat>Lihat<span class="sr-only"> {{ $i->label() }}</span></a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($changed)<p class="mt-2 text-[13px] text-gray-600">File berubah sejak analisa terakhir. Klik Analisa ulang untuk menilai butir terkait.</p>@endif
                </div>
            @endif

            {{-- Ringkasan: rekomendasi keseluruhan, ditukar polling saat analisa selesai --}}
            <section id="bagian-ringkasan" data-bagian="ringkasan" aria-label="Ringkasan" class="space-y-4">
                <p class="rounded-md bg-blue-50 px-3 py-2 text-sm text-arang" role="status" hidden
                   data-status-analisa-url="{{ route('folders.status_analisa', $folder) }}" data-active="{{ $berjalan ? '1' : '0' }}">Sedang dianalisa…</p>
                <div id="detail-folder" class="min-w-0">
                    @include('folders.partials.detail', ['done' => $done ? (clone $done)->setAttribute('suggestions', []) : null])
                </div>
            </section>

            @if ($kodeOn || $koordinator)
                <section id="bagian-kode" data-bagian="kode" class="space-y-4">
                    @if ($kodeOn)@include('folders.partials.codes')@endif
                    @if ($koordinator)@include('folders.partials.dasar-koding')@endif
                </section>
            @endif

            {{-- Kelengkapan: Daftar periksa 14 butir + saran DPJP --}}
            <section id="bagian-kelengkapan" data-bagian="kelengkapan" class="space-y-4">
                <div class="vf-kartu" aria-labelledby="judul-periksa" data-daftar-periksa>
                    <h2 id="judul-periksa" class="vf-judul">Daftar periksa</h2>
                    <div class="mt-3 divide-y divide-gray-100">
                        @foreach ($rubric as $kelompok => $butir)
                            <div class="py-3 first:pt-0 last:pb-0">
                                <h3 class="text-[13px] font-medium tracking-wide text-gray-500">{{ $kelompok }}</h3>
                                <ul class="mt-2 space-y-2">
                                    @foreach ($butir as $i)
                                        @php
                                            [$ikon, $warna, $teks] = match ($i->status) {
                                                'lengkap' => ['✓', 'text-green-700', 'Lengkap'],
                                                'kurang' => ['✕', 'text-red-700', 'Kurang'],
                                                'tidak_berlaku' => ['–', 'text-gray-500', 'Tidak berlaku'],
                                                'diterima' => ['✓', 'text-blue-700', 'Diterima'],
                                                default => ['○', 'text-gray-500', 'Belum dinilai'],
                                            };
                                            $url = $tautan($i->dokumen, $i->halaman);
                                        @endphp
                                        <li id="butir-{{ $i->kode }}" class="vf-sasaran flex items-start gap-3 rounded-md" data-butir="{{ $i->kode }}" data-butir-status="{{ $i->status ?? 'belum' }}">
                                            <span class="w-4 shrink-0 text-center font-semibold {{ $warna }}" aria-hidden="true">{{ $ikon }}</span>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-arang">{{ $i->label() }}<span class="sr-only">: {{ $teks }}</span>
                                                    @unless ($i->required())<span class="text-[13px] text-gray-500">(bila ada)</span>@endunless
                                                </p>
                                                @if ($i->catatan || $i->dokumen)
                                                    <p class="text-[13px] text-gray-500">{{ $i->catatan }}
                                                        @if ($url) · <a href="{{ $url }}" data-preview="{{ $i->dokumen }}" target="_blank" rel="noopener" class="text-url underline">{{ $i->dokumen }}@if ($i->halaman) hal. {{ $i->halaman }}@endif</a>
                                                        @elseif ($i->dokumen) · {{ $i->dokumen }}@endif
                                                    </p>
                                                @endif
                                                @if ($i->status === 'kurang')
                                                    @include('folders.partials.regulation', ['kode' => $i->kode])
                                                @endif
                                                @if ($i->status === 'diterima')
                                                    <p class="text-[13px] text-blue-800">Diterima: {{ $i->accepted_reason }}</p>
                                                @endif
                                            </div>
                                            @can('folders.analyze')
                                                @if ($i->status === 'kurang')
                                                    <details class="shrink-0">
                                                        <summary class="btn-ghost cursor-pointer text-sm">Terima</summary>
                                                        <form method="POST" action="{{ route('folders.rubric.accept', [$folder, $i->kode]) }}" class="mt-2 w-64 space-y-2">
                                                            @csrf
                                                            <label for="alasan-{{ $i->kode }}" class="label">Alasan menerima</label>
                                                            <input id="alasan-{{ $i->kode }}" name="alasan" required minlength="3" maxlength="500" class="field">
                                                            <button type="submit" class="btn-secondary">Terima butir</button>
                                                        </form>
                                                    </details>
                                                @elseif ($i->status === 'diterima')
                                                    <form method="POST" action="{{ route('folders.rubric.unaccept', [$folder, $i->kode]) }}" class="shrink-0">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="btn-ghost text-sm">Batalkan</button>
                                                    </form>
                                                @endif
                                            @endcan
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($saranOn)
                    @include('folders.partials.suggestions')
                @endif
            </section>

            @include('folders.partials.temuan')

            @if ($koordinator)
                <section id="bagian-pnpk" data-bagian="pnpk">@include('folders.partials.pnpk')</section>
            @endif

            @if ($bpjs)
                @include('folders.partials.bpjs')
            @endif

            @if ($sanggahan)
                <section id="bagian-sanggahan" data-bagian="sanggahan">
                    @include('folders.partials.dasar-sanggahan', ['drafUrl' => route('folders.draf', $folder), 'unduhUrl' => route('folders.draf.unduh', $folder)])
                </section>
            @endif

            @if ($koordinator)
                <section id="bagian-tambahan" data-bagian="tambahan">@include('folders.partials.manual')</section>
            @endif

            {{-- File: daftar ringkas per file; klik membuka di penampil --}}
            <section id="bagian-file" data-bagian="file" class="vf-kartu" aria-labelledby="judul-file" data-gulir-file>
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="judul-file" class="vf-judul">File ({{ $total }})</h2>
                    <p class="text-[13px] text-gray-500" data-docs-progress>{{ $selesai }} dari {{ $total }} file selesai</p>
                </div>
                @if ($folder->missing_at)
                    <p class="mt-1 text-[13px] text-gray-500">Dokumen dibuka dari salinan arsip CERMAT</p>
                @endif
                @if ($total)
                    <div class="mt-2 h-1 w-full overflow-hidden rounded-full bg-gray-200" role="progressbar" aria-label="Kemajuan baca file"
                         aria-valuemin="0" aria-valuemax="{{ $total }}" aria-valuenow="{{ $selesai }}" data-docs-bar>
                        <div class="h-full rounded-full bg-green-600 transition-[width] duration-200 motion-reduce:transition-none" style="width: {{ round($selesai / $total * 100) }}%"></div>
                    </div>
                    <div class="mt-3 space-y-2" data-docs-list>
                        @foreach ($documents as $d)
                            @include('folders.partials.document')
                            <p class="-mt-1 px-1 text-xs {{ $d->stored_path ? 'text-green-700' : 'text-gray-500' }}" data-arsip>{{ $d->stored_path ? 'tersimpan' : 'tidak tersimpan' }}</p>
                        @endforeach
                    </div>
                @else
                    <p class="mt-2 text-[13px] text-gray-500">Belum ada file yang dibaca.</p>
                @endif
            </section>
        </div>

        @include('folders.partials.penampil')
    </div>

    {{-- Bantuan pintasan keyboard (?) --}}
    <dialog class="m-auto w-[min(92vw,26rem)] rounded-2xl p-0 shadow-xl backdrop:bg-black/40" aria-labelledby="judul-pintasan" data-bantuan-pintasan>
        <div class="p-5">
            <h2 id="judul-pintasan" class="vf-judul">Pintasan keyboard</h2>
            <dl class="mt-3 grid grid-cols-[4rem_1fr] gap-y-2 text-[15px]">
                <dt><kbd class="vf-kbd">J</kbd></dt><dd>Temuan berikutnya</dd>
                <dt><kbd class="vf-kbd">K</kbd></dt><dd>Temuan sebelumnya</dd>
                <dt><kbd class="vf-kbd">X</kbd></dt><dd>Centang / lepas temuan terfokus</dd>
                <dt><kbd class="vf-kbd">?</kbd></dt><dd>Tampilkan bantuan ini</dd>
                <dt><kbd class="vf-kbd">Esc</kbd></dt><dd>Tutup penampil / bantuan</dd>
            </dl>
            <form method="dialog" class="mt-4 text-right"><button class="btn-secondary">Tutup</button></form>
        </div>
    </dialog>
</div>
@vite(['resources/js/folder-status.js', 'resources/js/manual-upload.js', 'resources/js/folder-page.js'])
</x-app-layout>
