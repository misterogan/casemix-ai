<x-app-layout title="Acuan regulasi · PNPK">
    <x-page-header title="Acuan regulasi" subtitle="PNPK: PDF resmi adalah sumber kebenaran. AI hanya mengusulkan; Koordinator menyetujui; hanya butir disetujui yang dipakai.">
        <x-slot:actions><a href="{{ route('acuan.pnpk.create') }}" class="btn-primary">Unggah PNPK</a></x-slot:actions>
    </x-page-header>
    @include('acuan._tabs')

    <section class="panel">
        <form method="GET" class="panel-body flex flex-wrap items-end gap-3 border-b border-garis">
            <x-field name="q" label="Cari judul / KMK / kode ICD">
                <input id="q" name="q" value="{{ $q }}" class="field" placeholder="mis. Pneumonia atau J18">
            </x-field>
            <x-field name="status" label="Status">
                <select id="status" name="status" class="field">
                    <option value="">Semua</option>
                    @foreach (\App\Models\PnpkDokumen::STATUS as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="isi" label="Cari di isi PNPK">
                <input id="isi" name="isi" value="{{ $isi }}" class="field" placeholder="kata dalam teks dokumen">
            </x-field>
            <button class="btn-secondary">Cari</button>
        </form>

        @if ($isi !== '')
            <div class="border-b border-garis px-5 py-3" data-hasil-isi>
                <p class="text-sm font-medium text-arang">Hasil di isi PNPK ({{ $hasil->count() }})</p>
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
            <x-empty-state title="Belum ada PNPK" text="Unggah PDF PNPK resmi (KMK). Teks diekstrak, AI mengusulkan butir berkutipan, lalu Koordinator meninjau."/>
        @else
            <ul class="divide-y divide-garis">
                @foreach ($list as $d)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm" data-pnpk="{{ $d->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-arang"><a href="{{ route('acuan.pnpk.show', $d) }}">{{ $d->judul }}</a>
                                <x-badge :variant="\App\Models\PnpkDokumen::STATUS_TONE[$d->status] ?? 'gray'">{{ \App\Models\PnpkDokumen::STATUS[$d->status] ?? $d->status }}</x-badge></p>
                            <p class="num text-xs text-tinta-3">{{ $d->nomor_kmk }} · {{ $d->tahun }} · berlaku {{ $d->tanggal_berlaku?->translatedFormat('d M Y') }}
                                · {{ $d->icd_disetujui }} ICD · {{ $d->butir_disetujui }} butir disetujui · {{ \App\Models\PnpkDokumen::PROSES[$d->status_proses] ?? $d->status_proses }}</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('acuan.pnpk.show', $d) }}" class="btn-secondary">{{ auth()->user()->hasRole('koordinator') ? 'Tinjau' : 'Lihat' }}</a>
                            <a href="{{ route('acuan.pnpk.edit', $d) }}" class="btn-ghost">Ubah</a>
                        </div>
                    </li>
                @endforeach
            </ul>
            <div class="px-5 py-3">{{ $list->links() }}</div>
        @endif
    </section>
</x-app-layout>
