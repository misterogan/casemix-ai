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
    </div>
</x-app-layout>
