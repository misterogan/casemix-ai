<x-app-layout title="Acuan regulasi">
    <x-page-header title="Acuan regulasi" subtitle="PNPK, Permenkes, BA kesepakatan, dan regulasi lain. PDF resmi adalah sumber kebenaran. AI hanya mengusulkan; Koordinator menyetujui; hanya butir disetujui yang dipakai.">
        <x-slot:actions><a href="{{ route('acuan.pnpk.create') }}" class="btn-primary">Unggah dokumen</a></x-slot:actions>
    </x-page-header>
    @include('acuan._tabs')

    <x-tabs :items="collect(\App\Models\RegulasiDokumen::TAB)->map(fn ($l, $k) => ['label' => $l, 'href' => route('acuan.pnpk.index', array_filter(['tab' => $k === 'semua' ? null : $k, 'q' => $q ?: null])), 'active' => $tab === $k])->values()->all()"
            label="Cakupan acuan" class="mb-4" data-tab-acuan/>

    <section class="panel">
        <form method="GET" class="panel-body flex flex-wrap items-end gap-3 border-b border-garis">
            @if ($tab !== 'semua')<input type="hidden" name="tab" value="{{ $tab }}">@endif
            <x-field name="q" label="Cari judul / nomor / kode ICD">
                <input id="q" name="q" value="{{ $q }}" class="field" placeholder="mis. Pneumonia, Permenkes 26/2021, J18">
            </x-field>
            <x-field name="status" label="Status">
                <select id="status" name="status" class="field">
                    <option value="">Semua</option>
                    @foreach (\App\Models\RegulasiDokumen::STATUS as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="isi" label="Cari di isi dokumen">
                <input id="isi" name="isi" value="{{ $isi }}" class="field" placeholder="kata dalam teks dokumen">
            </x-field>
            <button class="btn-secondary">Cari</button>
        </form>

        @if ($isi !== '')
            <div class="border-b border-garis px-5 py-3" data-hasil-isi>
                <p class="text-sm font-medium text-arang">Hasil di isi dokumen ({{ $hasil->count() }})</p>
                <ul class="mt-2 space-y-2 text-sm">
                    @forelse ($hasil as $h)
                        <li><a class="font-medium text-url" href="{{ route('acuan.pnpk.show', $h->dokumen_id) }}?hal={{ $h->halaman }}">{{ $h->judul }} · hal. {{ $h->halaman }}</a>
                            <p class="text-xs text-tinta-3">{!! $h->potongan !!}</p></li>
                    @empty
                        <li class="text-tinta-3">Tidak ditemukan.</li>
                    @endforelse
                </ul>
            </div>
        @endif

        @if ($list->isEmpty())
            <x-empty-state title="Belum ada dokumen" text="Unggah PDF resmi (PNPK, Permenkes, BA kesepakatan, dll.). Teks diekstrak, AI mengusulkan butir berkutipan, lalu Koordinator meninjau."/>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-tinta-3">
                        <tr class="border-b border-garis">
                            <th class="px-5 py-2 font-medium">Judul</th><th class="px-3 py-2 font-medium">Nomor</th><th class="px-3 py-2 font-medium">Jenis</th>
                            <th class="px-3 py-2 font-medium">Tahun</th><th class="px-3 py-2 font-medium">Status</th><th class="px-3 py-2 font-medium">Butir disetujui</th>
                            <th class="px-3 py-2"><span class="sr-only">Aksi</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-garis">
                        @foreach ($list as $d)
                            <tr data-pnpk="{{ $d->id }}">
                                <td class="px-5 py-3 align-top">
                                    <a class="font-medium text-arang" href="{{ route('acuan.pnpk.show', $d) }}">{{ $d->judul }}</a>
                                    @if ($d->perlu_dilengkapi)<x-badge variant="warning">Perlu dilengkapi</x-badge>@endif
                                    <p class="text-xs text-tinta-3">{{ \App\Models\RegulasiDokumen::PROSES[$d->status_proses] ?? $d->status_proses }}
                                        @if ($d->tanggal_berlaku) · berlaku {{ $d->tanggal_berlaku->translatedFormat('d M Y') }}@endif
                                        @if ($d->mencabut_teks) · mencabut {{ $d->mencabut_teks }}@endif
                                        @if ($d->penggantiDokumen) · diganti oleh {{ $d->penggantiDokumen->judul }}@endif</p>
                                </td>
                                <td class="num px-3 py-3 align-top">{{ $d->nomorTampil() ?? 'Nomor belum ada' }}</td>
                                <td class="px-3 py-3 align-top"><x-badge :variant="\App\Models\RegulasiDokumen::JENIS_TONE[$d->jenis] ?? 'gray'">{{ $d->jenisLabel() }}</x-badge></td>
                                <td class="num px-3 py-3 align-top">{{ $d->tahun }}</td>
                                <td class="px-3 py-3 align-top"><x-badge :variant="\App\Models\RegulasiDokumen::STATUS_TONE[$d->status] ?? 'gray'">{{ \App\Models\RegulasiDokumen::STATUS[$d->status] ?? $d->status }}</x-badge></td>
                                <td class="num px-3 py-3 align-top">{{ $d->butir_disetujui }}
                                    @if ($d->butir_belum_diperiksa > 0)<span class="block text-xs text-warning-600">{{ $d->butir_belum_diperiksa }} belum diperiksa</span>@endif</td>
                                <td class="whitespace-nowrap px-3 py-3 text-right align-top">
                                    <a href="{{ route('acuan.pnpk.show', $d) }}" class="btn-secondary">{{ auth()->user()->hasRole('koordinator') ? 'Tinjau' : 'Lihat' }}</a>
                                    <a href="{{ route('acuan.pnpk.edit', $d) }}" class="btn-ghost">Ubah</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 py-3">{{ $list->links() }}</div>
        @endif
    </section>
</x-app-layout>
