<x-app-layout :title="$dokumen->exists ? 'Ubah PNPK' : 'Unggah PNPK'">
    <x-page-header :title="$dokumen->exists ? 'Ubah metadata PNPK' : 'Unggah PNPK'" :back="route('acuan.pnpk.index')"/>

    <form method="POST" enctype="multipart/form-data" action="{{ $dokumen->exists ? route('acuan.pnpk.update', $dokumen) : route('acuan.pnpk.store') }}" class="panel">
        @csrf
        @if ($dokumen->exists) @method('PUT') @endif
        <div class="panel-body grid gap-4 sm:grid-cols-2">
            <x-field name="judul" label="Judul" required class="sm:col-span-2" hint="Contoh: PNPK Tata Laksana Pneumonia">
                <input id="judul" name="judul" value="{{ old('judul', $dokumen->judul) }}" required maxlength="255" class="field">
            </x-field>
            <x-field name="nomor_kmk" label="Nomor KMK" required>
                <input id="nomor_kmk" name="nomor_kmk" value="{{ old('nomor_kmk', $dokumen->nomor_kmk) }}" required maxlength="120" class="field">
            </x-field>
            <x-field name="tahun" label="Tahun" required>
                <input id="tahun" type="number" name="tahun" value="{{ old('tahun', $dokumen->tahun) }}" required min="1990" max="2100" class="field num">
            </x-field>
            <x-field name="tanggal_berlaku" label="Tanggal berlaku" required>
                <input id="tanggal_berlaku" type="date" name="tanggal_berlaku" value="{{ old('tanggal_berlaku', $dokumen->tanggal_berlaku?->format('Y-m-d')) }}" required class="field">
            </x-field>
            <x-field name="sumber_url" label="Sumber URL (opsional)">
                <input id="sumber_url" type="url" name="sumber_url" value="{{ old('sumber_url', $dokumen->sumber_url) }}" maxlength="500" class="field" placeholder="https://…">
            </x-field>
            @if ($dokumen->exists)
                <x-field name="status" label="Status">
                    <select id="status" name="status" class="field">
                        @foreach (\App\Models\PnpkDokumen::STATUS as $k => $l)<option value="{{ $k }}" @selected(old('status', $dokumen->status) === $k)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
                <x-field name="diganti_oleh_id" label="Diganti oleh (opsional)" hint="Diisi → status menjadi Diganti.">
                    <select id="diganti_oleh_id" name="diganti_oleh_id" class="field">
                        <option value="">—</option>
                        @foreach ($penggantiPilihan ?? [] as $p)<option value="{{ $p->id }}" @selected((string) old('diganti_oleh_id', $dokumen->diganti_oleh_id) === (string) $p->id)>{{ $p->judul }} ({{ $p->nomor_kmk ?? '—' }}, {{ $p->tahun }})</option>@endforeach
                    </select>
                </x-field>
            @else
                <x-field name="pdf" label="PDF resmi" required hint="Maks 50 MB. PDF yang sama tidak bisa diunggah dua kali.">
                    <input id="pdf" type="file" name="pdf" accept="application/pdf" required class="field">
                </x-field>
                <x-field name="menggantikan_id" label="Menggantikan PNPK (opsional)" hint="PNPK lama menjadi berstatus Diganti.">
                    <select id="menggantikan_id" name="menggantikan_id" class="field">
                        <option value="">—</option>
                        @foreach ($pilihan as $p)<option value="{{ $p->id }}" @selected((string) old('menggantikan_id') === (string) $p->id)>{{ $p->judul }} ({{ $p->nomor_kmk }}, {{ $p->tahun }})</option>@endforeach
                    </select>
                </x-field>
            @endif
        </div>
        <div class="flex justify-end border-t border-garis px-5 py-3"><button class="btn-primary">{{ $dokumen->exists ? 'Simpan' : 'Unggah' }}</button></div>
    </form>
    @if ($dokumen->exists)
        <form method="POST" action="{{ route('acuan.pnpk.destroy', $dokumen) }}" class="mt-4 flex justify-end" onsubmit="return confirm('Hapus PNPK ini beserta seluruh butirnya?')">
            @csrf @method('DELETE')<button class="btn-ghost">Hapus PNPK</button>
        </form>
    @endif
</x-app-layout>
