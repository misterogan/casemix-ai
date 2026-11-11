<x-app-layout :title="$provider->exists ? 'Ubah penyedia AI' : 'Tambah penyedia AI'">
    <x-page-header :title="$provider->exists ? 'Ubah penyedia AI' : 'Tambah penyedia AI'" :back="route('admin.providers.index')"/>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <form method="POST" action="{{ $provider->exists ? route('admin.providers.update', $provider) : route('admin.providers.store') }}" class="panel">
            @csrf
            @if ($provider->exists) @method('PUT') @endif
            <div class="panel-body grid gap-4 sm:grid-cols-2">
                <x-field name="name" label="Nama" required hint="Contoh: Telkomsel OCTA, Claude API">
                    <input id="name" name="name" value="{{ old('name', $provider->name) }}" required class="field">
                </x-field>
                <x-field name="driver" label="Jenis API" required>
                    <select id="driver" name="driver" class="field">
                        @foreach (\App\Models\AiProvider::DRIVERS as $k => $l)<option value="{{ $k }}" @selected(old('driver', $provider->driver) === $k)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
                <x-field name="base_url" label="Base URL" class="sm:col-span-2" hint="OpenAI-compatible: alamat sampai /v1 dari dokumentasi penyedia. Anthropic: kosongkan.">
                    <input id="base_url" name="base_url" value="{{ old('base_url', $provider->base_url) }}" class="field" placeholder="https://…/v1">
                </x-field>
                <x-field name="api_key" label="API key" class="sm:col-span-2"
                         :hint="$provider->exists ? 'Tersimpan terenkripsi (' . $provider->maskedKey() . '). Kosongkan bila tidak diganti.' : 'Disimpan terenkripsi di database.'">
                    <input id="api_key" type="password" name="api_key" autocomplete="off" class="field">
                </x-field>
                <x-field name="model_fast" label="Model cepat" required hint="Untuk memilah jenis dokumen saat impor.">
                    <input id="model_fast" name="model_fast" value="{{ old('model_fast', $provider->model_fast) }}" required class="field">
                </x-field>
                <x-field name="model_standard" label="Model standar" required hint="Untuk pemeriksaan berkas lengkap.">
                    <input id="model_standard" name="model_standard" value="{{ old('model_standard', $provider->model_standard) }}" required class="field">
                </x-field>
                <x-field name="max_tokens" label="Batas token jawaban" required>
                    <input id="max_tokens" type="number" name="max_tokens" value="{{ old('max_tokens', $provider->max_tokens) }}" class="field num">
                </x-field>
                <x-field name="timeout" label="Batas waktu (detik)" required>
                    <input id="timeout" type="number" name="timeout" value="{{ old('timeout', $provider->timeout) }}" class="field num">
                </x-field>
                <x-field name="data_location" label="Lokasi pemrosesan data" class="sm:col-span-2" hint="Catat di mana data diproses dan dasar perjanjiannya, untuk keperluan UU PDP.">
                    <input id="data_location" name="data_location" value="{{ old('data_location', $provider->data_location) }}" class="field" placeholder="Contoh: Jakarta, Indonesia — DPA ditandatangani 2026">
                </x-field>
                <x-field name="notes" label="Catatan" class="sm:col-span-2">
                    <textarea id="notes" name="notes" rows="2" class="field">{{ old('notes', $provider->notes) }}</textarea>
                </x-field>
                <div class="flex flex-wrap gap-x-6 gap-y-2 sm:col-span-2">
                    @foreach (['supports_images' => 'Model bisa membaca gambar/scan', 'is_active' => 'Aktif', 'is_default' => 'Jadikan penyedia utama'] as $f => $l)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="hidden" name="{{ $f }}" value="0">
                            <input type="checkbox" name="{{ $f }}" value="1" @checked(old($f, $provider->$f)) class="size-4 rounded border-garis-tua accent-giok"> {{ $l }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 border-t border-garis px-5 py-3">
                @if ($provider->exists)
                    <button form="hapus" class="btn-danger">Hapus</button>
                @else <span></span> @endif
                <div class="flex gap-2">
                    <a href="{{ route('admin.providers.index') }}" class="btn-ghost">Batal</a>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </div>
        </form>
        @if ($provider->exists)
            <form id="hapus" method="POST" action="{{ route('admin.providers.destroy', $provider) }}" data-confirm="Hapus penyedia {{ $provider->name }}?">@csrf @method('DELETE')</form>
        @endif

        <aside class="space-y-4 text-sm leading-relaxed text-tinta-2">
            <section class="panel panel-body">
                <h2 class="panel-title">Sebelum memakai penyedia</h2>
                <ul class="mt-2 list-disc space-y-1.5 pl-5">
                    <li>Pastikan model bisa membaca scan bila berkas berupa PDF hasil pindai.</li>
                    <li>Tanyakan lokasi pemrosesan data, apakah prompt disimpan atau dipakai untuk pelatihan, dan kesediaan menandatangani perjanjian pemrosesan data.</li>
                    <li>Uji dengan dokumen contoh yang identitasnya disamarkan, lalu bandingkan hasilnya antarpenyedia.</li>
                </ul>
            </section>
            <section class="rounded-lg border border-manila-tua bg-manila/40 p-4">
                Agregator lokal dan model di server sendiri (vLLM, Ollama) umumnya memakai format OpenAI-compatible. Claude API langsung memakai jenis Anthropic.
            </section>
        </aside>
    </div>
</x-app-layout>
