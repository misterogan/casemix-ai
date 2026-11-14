@php
    $tone = ['belum' => 'tone-neutral', 'proses' => 'tone-neutral', 'temuan' => 'tone-warn', 'lengkap' => 'tone-ok', 'berubah' => 'tone-done', 'gagal' => 'tone-crit'];
    $syncAt = $lastSync['at'] ?? null;
@endphp
<x-app-layout title="Verifikasi berkas">
    <x-page-header title="Verifikasi berkas" subtitle="Folder pasien dari FTP DHealth. Klik folder untuk melihat rekomendasi AI: apa yang kurang dan di dokumen mana.">
        <x-slot:meta>
            <p class="mt-2 text-xs text-tinta-3">
                @if ($syncAt)
                    Daftar diperbarui otomatis tiap jam · terakhir {{ \Illuminate\Support\Carbon::parse($syncAt)->timezone(config('app.timezone'))->translatedFormat('d M Y H:i') }}
                    @if (! ($lastSync['ok'] ?? true))<span class="text-kritis"> · gagal terhubung ke FTP, dicoba lagi jam berikutnya</span>@endif
                @else
                    Daftar diperbarui otomatis tiap jam · belum pernah tersinkron
                @endif
            </p>
        </x-slot:meta>
    </x-page-header>

    <div class="flex flex-wrap items-end gap-1 overflow-x-auto" role="tablist" aria-label="Filter status">
        <a href="{{ route('folders.index', request()->except('status', 'page')) }}"
           @class(['folder-tab', 'bg-white! border-garis!' => $state]) role="tab" aria-selected="{{ $state ? 'false' : 'true' }}">Semua</a>
        @foreach (\App\Models\FtpFolder::STATUSES as $k => $label)
            <a href="{{ route('folders.index', array_merge(request()->except('page'), ['status' => $k])) }}"
               @class(['folder-tab', 'bg-white! border-garis! font-medium! text-tinta-2!' => $state !== $k])
               role="tab" aria-selected="{{ $state === $k ? 'true' : 'false' }}">{{ $label }} <span class="num text-tinta-3">{{ $counts[$k] }}</span></a>
        @endforeach
    </div>

    <section class="panel rounded-tl-none">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-garis px-4 py-3">
            @if ($state)<input type="hidden" name="status" value="{{ $state }}">@endif
            <div class="min-w-60 flex-1">
                <label for="q" class="sr-only">Cari</label>
                <input id="q" name="q" value="{{ $q }}" class="field" placeholder="Cari nama pasien, No. RM, atau No. SEP">
            </div>
            <button class="btn-secondary">Cari</button>
            @if ($q !== '')<a href="{{ route('folders.index', $state ? ['status' => $state] : []) }}" class="btn-ghost">Hapus pencarian</a>@endif
        </form>

        @if ($folders->isEmpty())
            <x-empty-state title="Tidak ada folder di sini"
                           text="{{ $q !== '' || $state ? 'Coba kata kunci atau filter lain.' : 'Folder pasien dari FTP DHealth akan muncul setelah sinkronisasi otomatis berikutnya.' }}"/>
        @else
            <ul class="divide-y divide-garis">
                @foreach ($folders as $f)
                    @php $s = $f->state(); @endphp
                    <li>
                        <a href="{{ route('folders.show', $f) }}" class="group block px-5 py-4 hover:bg-giok-muda/40">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <p class="text-[17px] font-semibold text-giok group-hover:underline">{{ $f->nama_pasien ?: $f->name }}</p>
                                <span class="badge {{ $tone[$s] }}">
                                    @if ($s === 'temuan')
                                        {{ $f->kritis_count ? $f->kritis_count . ' kritis' : '' }}{{ $f->kritis_count && $f->perhatian_count ? ' · ' : '' }}{{ $f->perhatian_count ? $f->perhatian_count . ' perhatian' : '' }}
                                    @else
                                        {{ \App\Models\FtpFolder::STATUSES[$s] }}
                                    @endif
                                </span>
                            </div>
                            <p class="num mt-0.5 text-sm text-tinta-2">
                                RM {{ $f->no_rm ?: '—' }} · SEP {{ $f->no_sep ?: '—' }} · {{ \App\Support\Eklaim::JENIS_RAWAT[$f->jenis_rawat] ?? 'Jenis rawat tidak terbaca' }}
                            </p>
                            <p class="mt-0.5 text-xs text-tinta-3">
                                {{ $f->file_count }} dokumen · diperbarui di FTP {{ $f->files_modified_at?->translatedFormat('d M Y H:i') }}
                                @if ($f->analyzed_at) · dianalisa {{ $f->analyzed_at->translatedFormat('d M H:i') }}@endif
                                · <span class="font-mono">{{ $f->name }}</span>
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="border-t border-garis px-4 py-3">{{ $folders->links() }}</div>
        @endif
    </section>
</x-app-layout>
