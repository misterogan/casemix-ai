<x-app-layout title="Berkas klaim">
    <x-page-header title="Berkas klaim" subtitle="Semua berkas klaim JKN yang sedang dan sudah diperiksa.">
        <x-slot:actions>
            @can('reports.download')
                <a href="{{ route('claims.export', request()->query()) }}" class="btn-secondary">Unduh CSV</a>
            @endcan
            @can('claims.import')<a href="{{ route('imports.index') }}" class="btn-secondary">Impor dari SIMRS</a>@endcan
            @can('claims.edit')<a href="{{ route('claims.create') }}" class="btn-primary">Buat berkas</a>@endcan
        </x-slot:actions>
    </x-page-header>

    {{-- Filter status sebagai tab map --}}
    <div class="flex flex-wrap items-end gap-1 overflow-x-auto" role="tablist" aria-label="Filter status">
        @php $current = request('status'); @endphp
        <a href="{{ route('claims.index', request()->except('status', 'page')) }}"
           @class(['folder-tab', 'bg-white! border-garis!' => $current]) role="tab" aria-selected="{{ $current ? 'false' : 'true' }}">Semua</a>
        @foreach ($statuses as $s)
            <a href="{{ route('claims.index', array_merge(request()->except('page'), ['status' => $s->value])) }}"
               @class(['folder-tab', 'bg-white! border-garis! font-medium! text-tinta-2!' => $current !== $s->value])
               role="tab" aria-selected="{{ $current === $s->value ? 'true' : 'false' }}">{{ $s->label() }}</a>
        @endforeach
    </div>

    <section class="panel rounded-tl-none">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-garis px-4 py-3">
            @if ($current)<input type="hidden" name="status" value="{{ $current }}">@endif
            <div class="min-w-60 flex-1">
                <label for="q" class="sr-only">Cari</label>
                <input id="q" name="q" value="{{ request('q') }}" class="field" placeholder="Cari nama, No. RM, No. SEP, atau DPJP">
            </div>
            <div>
                <label for="jenis_rawat" class="label text-xs">Jenis rawat</label>
                <select id="jenis_rawat" name="jenis_rawat" class="field">
                    <option value="">Semua</option>
                    @foreach (\App\Support\Eklaim::JENIS_RAWAT as $k => $v)
                        <option value="{{ $k }}" @selected(request('jenis_rawat') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="dari" class="label text-xs">Pulang dari</label>
                <input id="dari" type="date" name="dari" value="{{ request('dari') }}" class="field">
            </div>
            <div>
                <label for="sampai" class="label text-xs">sampai</label>
                <input id="sampai" type="date" name="sampai" value="{{ request('sampai') }}" class="field">
            </div>
            <button class="btn-secondary">Terapkan</button>
            @if (request()->hasAny(['q', 'jenis_rawat', 'dari', 'sampai']))
                <a href="{{ route('claims.index', $current ? ['status' => $current] : []) }}" class="btn-ghost">Hapus filter</a>
            @endif
        </form>

        @if ($claims->isEmpty())
            <x-empty-state title="Belum ada berkas di sini"
                           text="Buat berkas baru atau impor scan dari folder FTP SIMRS. AI akan memilah dokumen per pasien.">
                @can('claims.edit')<a href="{{ route('claims.create') }}" class="btn-primary">Buat berkas</a>@endcan
            </x-empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Pasien</th>
                        <th>No. SEP</th>
                        <th>Rawat</th>
                        <th>DPJP</th>
                        <th>Dokumen</th>
                        <th>Hasil AI</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($claims as $c)
                        <tr class="row-link cursor-pointer" onclick="if(!event.target.closest('a'))location.href='{{ route('claims.show', $c) }}'">
                            <td>
                                <a href="{{ route('claims.show', $c) }}" class="font-semibold text-tinta hover:text-giok">{{ $c->nama_pasien }}</a>
                                <p class="num text-xs text-tinta-3">RM {{ $c->no_rm }}</p>
                            </td>
                            <td class="num">{{ $c->no_sep ?: '—' }}</td>
                            <td>
                                {{ \App\Support\Eklaim::JENIS_RAWAT[$c->jenis_rawat] ?? $c->jenis_rawat }}
                                <p class="num text-xs text-tinta-3">{{ $c->tanggal_masuk?->format('d/m/y') }}@if($c->tanggal_keluar) – {{ $c->tanggal_keluar->format('d/m/y') }}@endif</p>
                            </td>
                            <td class="max-w-48 truncate">{{ $c->dpjp }}</td>
                            <td class="num">{{ $c->documents_count }}</td>
                            <td>
                                @php $r = $c->latestReview; @endphp
                                @if (! $r)
                                    <span class="text-xs text-tinta-3">Belum diperiksa</span>
                                @elseif ($r->status->isActive())
                                    <span class="text-xs text-giok">{{ $r->status->label() }}…</span>
                                @elseif ($r->status === \App\Enums\ReviewStatus::Gagal)
                                    <span class="text-xs text-kritis">Gagal</span>
                                @else
                                    <span class="flex flex-wrap gap-1">
                                        @if ($k = $r->countBy(\App\Enums\Severity::Kritis))<span class="badge tone-crit">{{ $k }}</span>@endif
                                        @if ($p = $r->countBy(\App\Enums\Severity::Perhatian))<span class="badge tone-warn">{{ $p }}</span>@endif
                                        @if (! $k && ! $p)<span class="badge tone-ok">Bersih</span>@endif
                                    </span>
                                @endif
                            </td>
                            <td><x-status-badge :status="$c->status"/></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $claims->links() }}
        @endif
    </section>
</x-app-layout>
