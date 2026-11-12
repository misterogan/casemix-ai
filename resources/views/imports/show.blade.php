<x-app-layout title="Tinjau impor">
    <x-page-header title="Tinjau hasil pemilahan" :back="route('imports.index')"
                   :subtitle="'Impor ' . $batch->created_at->format('d/m/Y H:i') . ' · ' . $batch->total . ' file'"/>

    @if ($batch->isProcessing())
        <section class="panel panel-body">
            <p class="font-semibold">{{ $batch->statusLabel() }}…</p>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-kertas" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $batch->total }}" aria-valuenow="{{ $batch->processed }}">
                <div class="h-full bg-giok" style="width: {{ $batch->total ? round($batch->processed / $batch->total * 100) : 0 }}%"></div>
            </div>
            <p class="num mt-2 text-sm text-tinta-2">{{ $batch->processed }} dari {{ $batch->total }} file. Halaman diperbarui otomatis.</p>
        </section>
        <script>setTimeout(() => location.reload(), 5000);</script>
    @elseif ($batch->status === 'gagal')
        <section class="panel panel-body text-sm text-kritis">{{ $batch->error }}</section>
    @elseif ($batch->status === 'disimpan')
        <section class="panel panel-body text-sm">
            Impor ini sudah disimpan pada {{ $batch->committed_at?->format('d/m/Y H:i') }}.
            <a href="{{ route('claims.index') }}" class="font-medium text-giok hover:underline">Buka daftar berkas</a>
        </section>
    @else
        <p class="mb-4 max-w-[75ch] text-sm text-tinta-2">
            Identitas pasien diambil dari nama folder FTP, jenis dokumen dari nama file. Periksa jenis dokumen yang belum tepat, misalnya file yang terbaca sebagai "Dokumen tambahan".
            Kelompok dengan SEP atau No. RM yang sudah ada akan ditambahkan ke berkas tersebut.
        </p>

        <form method="POST" action="{{ route('imports.commit', $batch) }}" class="space-y-5">
            @csrf
            @php $keys = $groups->keys(); @endphp
            @foreach ($groups as $key => $items)
                @php
                    $d = $items->pluck('detected');
                    $pick = fn ($k) => $d->pluck($k)->filter()->countBy()->sortDesc()->keys()->first();
                    $gi = $loop->index;
                @endphp
                <section class="panel">
                    <div class="grid gap-3 border-b border-garis bg-[#FAFBF9] px-5 py-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div class="lg:col-span-2">
                            <label class="label text-xs" for="g{{ $gi }}nama">Nama pasien</label>
                            <input id="g{{ $gi }}nama" name="groups[{{ $key }}][nama]" value="{{ $pick('nama') }}" class="field py-1.5">
                        </div>
                        <div>
                            <label class="label text-xs" for="g{{ $gi }}rm">No. RM</label>
                            <input id="g{{ $gi }}rm" name="groups[{{ $key }}][no_rm]" value="{{ $pick('no_rm') }}" class="field num py-1.5">
                        </div>
                        <div>
                            <label class="label text-xs" for="g{{ $gi }}sep">No. SEP</label>
                            <input id="g{{ $gi }}sep" name="groups[{{ $key }}][no_sep]" value="{{ $pick('no_sep') }}" class="field num py-1.5">
                        </div>
                        <div>
                            <label class="label text-xs" for="g{{ $gi }}jr">Jenis rawat</label>
                            <select id="g{{ $gi }}jr" name="groups[{{ $key }}][jenis_rawat]" class="field py-1.5">
                                @foreach (\App\Support\Eklaim::JENIS_RAWAT as $k => $l)<option value="{{ $k }}" @selected($pick('jenis_rawat') === $k)>{{ $l }}</option>@endforeach
                            </select>
                        </div>
                        @if ($pick('folder'))
                            <p class="text-xs text-tinta-3 sm:col-span-2 lg:col-span-5">Folder FTP: <span class="num">{{ $pick('folder') }}</span></p>
                        @endif
                        @if ($key === 'tidak-dikenal')
                            <p class="text-sm text-waspada sm:col-span-2 lg:col-span-5">Identitas pada file berikut tidak terbaca. Pindahkan ke kelompok pasien yang benar atau lewati.</p>
                        @endif
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead><tr><th>File</th><th>Terbaca</th><th class="w-52">Jenis dokumen</th><th class="w-56">Kelompok</th><th class="w-20">Lewati</th></tr></thead>
                            <tbody>
                            @foreach ($items as $item)
                                <tr>
                                    <td class="max-w-64"><p class="truncate font-medium" title="{{ $item->original_name }}">{{ $item->original_name }}</p></td>
                                    <td class="text-xs text-tinta-2">
                                        {{ $item->detected['nama'] ?? '—' }}<br>
                                        <span class="num">RM {{ $item->detected['no_rm'] ?? '—' }} · SEP {{ $item->detected['no_sep'] ?? '—' }}</span><br>
                                        <span @class(['text-waspada' => ($item->detected['keyakinan'] ?? '') === 'rendah'])>keyakinan {{ $item->detected['keyakinan'] ?? '—' }}</span>
                                    </td>
                                    <td>
                                        <select name="items[{{ $item->id }}][type]" class="field py-1.5" aria-label="Jenis dokumen {{ $item->original_name }}">
                                            @foreach ($types as $t)<option value="{{ $t->value }}" @selected($item->detected_type === $t->value)>{{ $t->label() }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <select name="items[{{ $item->id }}][group_key]" class="field py-1.5" aria-label="Kelompok pasien">
                                            @foreach ($keys as $k)
                                                @php $gd = $groups[$k]->pluck('detected'); @endphp
                                                <option value="{{ $k }}" @selected($k === $key)>{{ $gd->pluck('nama')->filter()->first() ?? $k }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="text-center">
                                        <input type="hidden" name="items[{{ $item->id }}][skip]" value="0">
                                        <input type="checkbox" name="items[{{ $item->id }}][skip]" value="1" @checked($key === 'tidak-dikenal') class="size-4 rounded border-garis-tua accent-giok" aria-label="Lewati {{ $item->original_name }}">
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            @if ($failed->isNotEmpty())
                <section class="panel panel-body text-sm">
                    <p class="font-semibold text-kritis">{{ $failed->count() }} file gagal dipilah dan tidak ikut disimpan</p>
                    <ul class="mt-2 space-y-1 text-tinta-2">
                        @foreach ($failed as $f)<li><span class="font-medium">{{ $f->original_name }}</span>: {{ \Illuminate\Support\Str::limit($f->error, 160) }}</li>@endforeach
                    </ul>
                </section>
            @endif

            <div class="sticky bottom-0 -mx-5 flex flex-wrap items-center justify-end gap-4 border-t border-garis bg-kertas/95 px-5 py-3 backdrop-blur lg:-mx-8 lg:px-8">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="periksa" value="1" checked class="size-4 rounded border-garis-tua accent-giok">
                    Langsung periksa semua berkas dengan AI
                </label>
                <button type="submit" class="btn-primary">Simpan {{ $groups->count() }} kelompok sebagai berkas</button>
            </div>
        </form>
    @endif
</x-app-layout>
