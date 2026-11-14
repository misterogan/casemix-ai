@php
    use App\Enums\ReviewStatus;
    use App\Support\ClaimFields;
    $active = $latest?->status?->isActive();
    $failed = $latest?->status === ReviewStatus::Gagal;
    $order = ['kritis' => 0, 'perhatian' => 1, 'sesuai' => 2];
    $findings = collect($done?->findings ?? [])->sortBy(fn ($f) => $order[$f['tingkat']] ?? 3)->values();
    $tone = ['kritis' => 'tone-crit', 'perhatian' => 'tone-warn', 'sesuai' => 'tone-ok'];
    $bar = ['kritis' => 'bg-kritis', 'perhatian' => 'bg-waspada', 'sesuai' => 'bg-sesuai'];
    $label = ['kritis' => 'Kritis', 'perhatian' => 'Perhatian', 'sesuai' => 'Sesuai'];
    $docLink = function (?string $name, ?int $hal) use ($folder, $linkable) {
        if (! $name) return null;
        return isset($linkable[$name]) ? route('folders.file', [$folder, $linkable[$name]]) . ($hal ? '#page=' . $hal : '') : null;
    };
    $title = $folder->nama_pasien ?: $folder->name;
@endphp
<x-app-layout :title="$title">
    <x-page-header :title="$title" :back="route('folders.index')">
        <x-slot:meta>
            <p class="num mt-1 text-sm text-tinta-2">RM {{ $folder->no_rm ?: '—' }} · SEP {{ $folder->no_sep ?: '—' }} · {{ \App\Support\Eklaim::JENIS_RAWAT[$folder->jenis_rawat] ?? 'Jenis rawat tidak terbaca' }}</p>
            <p class="mt-1 font-mono text-xs text-tinta-3">{{ $folder->name }}</p>
        </x-slot:meta>
        <x-slot:actions>
            @can('folders.analyze')
                @unless ($folder->missing_at)
                    <form method="POST" action="{{ route('folders.analyze', $folder) }}">
                        @csrf
                        <button type="submit" class="btn-primary" @disabled($active || $blocker) @if ($blocker) title="{{ $blocker }}" @endif>Analisa ulang dengan AI</button>
                    </form>
                @endunless
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="min-w-0 space-y-4">
            @if ($folder->missing_at)
                <p class="rounded-md border border-garis bg-white px-4 py-3 text-sm text-tinta-2">Folder ini sudah tidak ada di FTP sejak {{ $folder->missing_at->translatedFormat('d M Y H:i') }}. Hasil analisa terakhir tetap ditampilkan.</p>
            @endif
            @if ($changed && ! $active)
                <p class="rounded-md border border-stempel/25 bg-[#ECE9F7] px-4 py-3 text-sm text-stempel">
                    <strong>File di folder ini berubah</strong> sejak dianalisa pada {{ $done->finished_at->translatedFormat('d M Y H:i') }}. Kalau dokumen sudah diperbaiki di DHealth, klik <em>Analisa ulang dengan AI</em>.
                </p>
            @endif
            @if ($blocker && ! $done && ! $active)
                <p class="rounded-md border border-waspada/25 bg-waspada-muda px-4 py-3 text-sm text-waspada">Folder ini belum dianalisa. {{ $blocker }}</p>
            @endif

            <section class="panel" aria-labelledby="hasil">
                <div class="panel-head">
                    <h2 id="hasil" class="panel-title">Rekomendasi AI</h2>
                    @if ($done)<span class="text-xs text-tinta-3">{{ $done->finished_at->translatedFormat('d M Y H:i') }} · {{ $done->requester?->name ?? 'Sistem' }}</span>@endif
                </div>

                @if ($active)
                    <div class="flex items-center gap-3 px-5 py-6 text-sm text-giok" data-poll="{{ route('folders.status', $folder) }}">
                        <svg class="size-5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 00-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        <span>AI sedang membaca {{ $folder->file_count }} dokumen di folder ini. Biasanya 1–3 menit; halaman diperbarui otomatis.</span>
                    </div>
                @endif
                @if ($failed && ! $active)
                    <div class="border-b border-garis px-5 py-3 text-sm text-kritis"><p class="font-semibold">Analisa terakhir gagal.</p><p class="mt-0.5">{{ $latest->error }}</p></div>
                @endif

                @if ($done)
                    <div @class(['panel-body', 'opacity-60' => $active])>
                        @if ($done->summary)<p class="max-w-[72ch] text-[15px] leading-relaxed">{{ $done->summary }}</p>@endif
                        <div class="mt-3 flex flex-wrap gap-2 text-sm">
                            <span class="badge tone-crit">{{ $done->countBy('kritis') }} kritis</span>
                            <span class="badge tone-warn">{{ $done->countBy('perhatian') }} perhatian</span>
                            <span class="badge tone-ok">{{ $done->countBy('sesuai') }} sesuai</span>
                        </div>
                    </div>
                    <ul @class(['divide-y divide-garis border-t border-garis', 'opacity-60' => $active])>
                        @foreach ($findings as $f)
                            @php $url = $docLink($f['dokumen'] ?? null, $f['halaman'] ?? null); @endphp
                            <li class="grid grid-cols-[6px_1fr] gap-4 px-5 py-3.5">
                                <span class="rounded-full {{ $bar[$f['tingkat']] ?? 'bg-waspada' }}" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="font-semibold">{{ $f['judul'] }}</p>
                                        <span class="badge {{ $tone[$f['tingkat']] ?? 'tone-warn' }}">{{ $label[$f['tingkat']] ?? 'Perhatian' }}</span>
                                    </div>
                                    @if (! empty($f['uraian']))<p class="mt-1 max-w-[75ch] text-sm leading-relaxed text-tinta-2">{{ $f['uraian'] }}</p>@endif
                                    <p class="mt-1 text-xs text-tinta-3">
                                        {{ $f['kategori'] ?? 'Lainnya' }}
                                        @if (! empty($f['dokumen']))
                                            · @if ($url)<a href="{{ $url }}" target="_blank" rel="noopener" class="font-medium text-giok hover:underline">{{ $f['dokumen'] }}@if (! empty($f['halaman'])), hal. {{ $f['halaman'] }}@endif ↗</a>@else{{ $f['dokumen'] }}@if (! empty($f['halaman'])), hal. {{ $f['halaman'] }}@endif @endif
                                        @endif
                                        · {{ ($f['sumber'] ?? '') === 'aturan' ? 'Aturan E-Klaim' : 'AI' }}
                                    </p>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    @if (! empty($done->suggestions))
                        <div class="border-t border-garis bg-manila/35 px-5 py-4">
                            <h3 class="font-semibold">Kondisi yang belum tertulis sebagai diagnosis</h3>
                            <p class="mt-0.5 text-sm text-tinta-2">Konfirmasi ke DPJP. Bukan diagnosis dan bukan kode ICD.</p>
                            <ul class="mt-3 space-y-3">
                                @foreach ($done->suggestions as $s)
                                    @php $url = $docLink($s['dokumen'] ?? null, $s['halaman'] ?? null); @endphp
                                    <li class="rounded-md border border-manila-tua bg-white px-4 py-3">
                                        <p class="font-semibold">{{ $s['kondisi'] }}</p>
                                        <p class="mt-1 text-sm text-tinta-2"><span class="font-medium text-tinta">Bukti:</span> {{ $s['bukti'] }}</p>
                                        @if (! empty($s['dokumen']))
                                            <p class="mt-1 text-xs">@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener" class="font-medium text-giok hover:underline">{{ $s['dokumen'] }}@if (! empty($s['halaman'])), hal. {{ $s['halaman'] }}@endif ↗</a>@else<span class="text-tinta-3">{{ $s['dokumen'] }}</span>@endif</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (! empty($done->extracted))
                        <details class="border-t border-garis px-5 py-3">
                            <summary class="cursor-pointer text-sm font-medium text-tinta-2">Data yang terbaca dari dokumen (dasar aturan E-Klaim)</summary>
                            <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                                @foreach ($done->extracted as $field => $v)
                                    <div class="flex justify-between gap-3 border-b border-garis/70 py-1"><dt class="text-tinta-3">{{ ClaimFields::label($field) }}</dt><dd class="num text-right">{{ ClaimFields::display($field, $v) }}</dd></div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                @elseif (! $active && ! $failed && ! $blocker)
                    <p class="px-5 py-6 text-sm text-tinta-3">Belum ada hasil analisa.</p>
                @endif
            </section>

            <p class="text-xs text-tinta-3">AI hanya memberi rekomendasi dan tidak memberi kode ICD. Perbaikan dokumen dilakukan di DHealth oleh yang berwenang; setelah itu folder ini akan tertanda <em>File berubah</em> pada sinkronisasi berikutnya.</p>
        </div>

        <aside class="space-y-4">
            <section class="panel">
                <div class="panel-head"><h2 class="panel-title">Dokumen di folder</h2><span class="num text-xs text-tinta-3">{{ $folder->file_count }}</span></div>
                <ul class="divide-y divide-garis">
                    @foreach ($folder->files as $i => $file)
                        <li class="px-5 py-2.5">
                            <a href="{{ route('folders.file', [$folder, $i]) }}" target="_blank" rel="noopener" class="text-sm font-medium text-giok hover:underline">{{ $file['name'] }} ↗</a>
                            <p class="num text-xs text-tinta-3">{{ \App\Services\Import\DocumentGuesser::type($file['name'])->label() }} · {{ number_format($file['size'] / 1024) }} KB</p>
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-garis px-5 py-2.5 text-xs text-tinta-3">Dibuka langsung dari FTP DHealth, tidak disalin.</p>
            </section>
        </aside>
    </div>
</x-app-layout>
