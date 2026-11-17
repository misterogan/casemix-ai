<x-app-layout :title="$regulation->exists ? 'Ubah acuan regulasi' : 'Tambah acuan regulasi'">
    <x-page-header :title="$regulation->exists ? 'Ubah acuan regulasi' : 'Tambah acuan regulasi'" :back="route('admin.regulations.index')"/>

    <form method="POST" action="{{ $regulation->exists ? route('admin.regulations.update', $regulation) : route('admin.regulations.store') }}" class="panel">
        @csrf
        @if ($regulation->exists) @method('PUT') @endif
        <div class="panel-body grid gap-4 sm:grid-cols-2">
            <x-field name="judul" label="Judul" required hint="Contoh: Permenkes 24/2022 Rekam Medis">
                <input id="judul" name="judul" value="{{ old('judul', $regulation->judul) }}" required maxlength="255" class="field">
            </x-field>
            <x-field name="pasal" label="Pasal">
                <input id="pasal" name="pasal" value="{{ old('pasal', $regulation->pasal) }}" maxlength="255" class="field">
            </x-field>
            <x-field name="ringkasan" label="Ringkasan" required class="sm:col-span-2">
                <textarea id="ringkasan" name="ringkasan" rows="5" required maxlength="5000" class="field">{{ old('ringkasan', $regulation->ringkasan) }}</textarea>
            </x-field>
            <x-field name="url" label="Tautan (opsional)" class="sm:col-span-2">
                <input id="url" type="url" name="url" value="{{ old('url', $regulation->url) }}" maxlength="500" class="field" placeholder="https://…">
            </x-field>
            <fieldset class="sm:col-span-2">
                <legend class="label">Butir Daftar periksa terkait</legend>
                <div class="mt-1 grid gap-1 sm:grid-cols-2">
                    @foreach (\App\Support\VerificationRubric::codes() as $k)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="butir[]" value="{{ $k }}" class="size-4" @checked(in_array($k, old('butir', $codes), true))>
                            {{ \App\Support\VerificationRubric::label($k) }}
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="aktif" value="0">
                <input type="checkbox" name="aktif" value="1" class="size-4" @checked(old('aktif', $regulation->aktif))> Aktif
            </label>
        </div>
        <div class="flex justify-end border-t border-garis px-5 py-3"><button class="btn-primary">Simpan</button></div>
    </form>
</x-app-layout>
