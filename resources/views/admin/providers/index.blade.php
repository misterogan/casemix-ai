<x-app-layout title="Penyedia AI">
    <x-page-header title="Penyedia AI" subtitle="Penyedia bertanda utama dipakai untuk semua pemilahan dan pemeriksaan.">
        <x-slot:actions><a href="{{ route('admin.providers.create') }}" class="btn-primary">Tambah penyedia</a></x-slot:actions>
    </x-page-header>

    @if ($providers->isEmpty())
        <section class="panel">
            <x-empty-state title="Belum ada penyedia AI"
                text="Tambahkan agregator lokal (Telkomsel OCTA, XLSMART TokenKu.ai), Claude API, atau model di server rumah sakit. Pastikan perjanjian pemrosesan data sudah sesuai kebijakan rumah sakit.">
                <a href="{{ route('admin.providers.create') }}" class="btn-primary">Tambah penyedia</a>
            </x-empty-state>
        </section>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($providers as $p)
                <section @class(['panel', 'border-giok! ring-1 ring-giok/30' => $p->is_default && $p->is_active])>
                    <div class="panel-head">
                        <div>
                            <h2 class="panel-title">{{ $p->name }}</h2>
                            <p class="text-xs text-tinta-3">{{ $p->driverLabel() }}</p>
                        </div>
                        <div class="flex gap-1.5">
                            @if ($p->is_default)<span class="badge tone-ok">Utama</span>@endif
                            @unless ($p->is_active)<span class="badge tone-neutral">Nonaktif</span>@endunless
                        </div>
                    </div>
                    <dl class="grid grid-cols-[130px_1fr] gap-x-3 gap-y-1.5 px-5 py-4 text-sm">
                        <dt class="text-tinta-3">Base URL</dt><dd class="truncate">{{ $p->base_url ?: 'bawaan' }}</dd>
                        <dt class="text-tinta-3">API key</dt><dd class="num">{{ $p->maskedKey() }}</dd>
                        <dt class="text-tinta-3">Model cepat</dt><dd>{{ $p->model_fast }}</dd>
                        <dt class="text-tinta-3">Model standar</dt><dd>{{ $p->model_standard }}</dd>
                        <dt class="text-tinta-3">Baca scan</dt><dd>{{ $p->supports_images ? 'Ya' : 'Tidak' }}</dd>
                        <dt class="text-tinta-3">Lokasi data</dt><dd>{{ $p->data_location ?: 'belum dicatat' }}</dd>
                    </dl>
                    <div class="flex justify-end gap-2 border-t border-garis px-5 py-3">
                        <form method="POST" action="{{ route('admin.providers.test', $p) }}">@csrf<button class="btn-secondary">Uji koneksi</button></form>
                        <a href="{{ route('admin.providers.edit', $p) }}" class="btn-secondary">Ubah</a>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-app-layout>
