<x-app-layout title="Pengaturan">
    <x-page-header title="Pengaturan dan FTP" subtitle="Nilai di halaman ini dibaca dari file .env server. Ubah .env lalu jalankan php artisan config:cache."/>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="panel">
            <div class="panel-head">
                <h2 class="panel-title">FTP SIMRS</h2>
                <form method="POST" action="{{ route('admin.settings.ftp') }}">@csrf<button class="btn-secondary">Uji koneksi</button></form>
            </div>
            <dl class="grid grid-cols-[170px_1fr] gap-x-3 gap-y-2 px-5 py-4 text-sm">
                @foreach ($ftp as $k => $v)<dt class="text-tinta-3">{{ $k }}</dt><dd class="num">{{ $v }}</dd>@endforeach
            </dl>
            @if ($insecure)
                <p class="border-t border-garis bg-waspada-muda px-5 py-3 text-sm text-waspada">
                    Koneksi memakai FTP biasa: kata sandi dan isi rekam medis terkirim tanpa enkripsi di jaringan. Aktifkan FTPS (SIMRS_FTP_SSL=true) atau SFTP bila server SIMRS mendukung, dan batasi akses FTP hanya dari server aplikasi ini.
                </p>
            @endif
        </section>

        <section class="panel">
            <div class="panel-head"><h2 class="panel-title">Aplikasi</h2></div>
            <dl class="grid grid-cols-[230px_1fr] gap-x-3 gap-y-2 px-5 py-4 text-sm">
                @foreach ($app as $k => $v)<dt class="text-tinta-3">{{ $k }}</dt><dd class="num">{{ $v }}</dd>@endforeach
            </dl>
        </section>

        <section class="panel lg:col-span-2" aria-labelledby="judul-analisa">
            <div class="panel-head"><h2 id="judul-analisa" class="panel-title">Analisa AI</h2></div>
            <form method="POST" action="{{ route('admin.settings.analysis') }}" class="space-y-4 px-5 py-4 text-sm">
                @csrf
                @method('PUT')
                <input type="hidden" name="saran_kondisi" value="0">
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="saran_kondisi" value="1" class="mt-0.5 size-4" @checked($conditionSuggestions) aria-describedby="ket-saran-kondisi">
                    <span>
                        <span class="font-medium">Tampilkan saran kondisi yang belum tertulis sebagai diagnosis (untuk dikonfirmasi ke DPJP)</span>
                        <span id="ket-saran-kondisi" class="mt-0.5 block text-tinta-3">Bila mati, AI hanya memeriksa kelengkapan dan konsistensi dokumen (administratif).</span>
                    </span>
                </label>
                <button type="submit" class="btn-primary">Simpan</button>
            </form>
        </section>
    </div>
</x-app-layout>
