@props(['title' => null, 'fullHeight' => false])
<!DOCTYPE html>
<html lang="id" @class(['lg:h-full lg:overflow-hidden' => $fullHeight])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' · ' : '' }}{{ config('casemix.app_name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['min-h-screen bg-paper text-ink', 'lg:h-full lg:overflow-hidden' => $fullHeight])>
@php
    // Menu utama: Koordinator membaca hasil verifikasi AI langsung dari folder FTP.
    // Menu berkas klaim, impor, dan ringkasan lama disembunyikan (datanya tetap ada).
    $nav = [
        ['route' => 'control.index', 'match' => 'control.*', 'label' => 'Dashboard', 'role' => 'koordinator',
         'icon' => 'M4 13h6V4H4v9zm0 7h6v-5H4v5zm10 0h6v-9h-6v9zm0-16v5h6V4h-6z'],
        ['route' => 'work-queue.index', 'match' => 'work-queue.*', 'label' => 'Antrean kerja', 'role' => 'koordinator',
         'icon' => 'M4 6h16M4 12h16M4 18h10'],
        ['route' => 'bpjs.index', 'match' => 'bpjs.*', 'label' => 'Klaim BPJS', 'role' => 'koordinator',
         'icon' => 'M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2zm2 9h4'],
        ['route' => 'folders.index', 'match' => 'folders.*', 'label' => 'Verifikasi berkas', 'can' => 'folders.view',
         'icon' => 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['route' => 'audit.index', 'match' => 'audit.*', 'label' => 'Riwayat aktivitas', 'can' => 'audit.view',
         'icon' => 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z'],
    ];
    $admin = [
        ['route' => 'admin.users.index', 'match' => 'admin.users.*', 'label' => 'Pengguna'],
        ['route' => 'admin.roles.index', 'match' => 'admin.roles.*', 'label' => 'Peran dan hak akses'],
        ['route' => 'admin.providers.index', 'match' => 'admin.providers.*', 'label' => 'Penyedia AI'],
        ['route' => 'admin.budget.index', 'match' => 'admin.budget.*', 'label' => 'Anggaran AI'],
        ['route' => 'admin.settings.index', 'match' => 'admin.settings.*', 'label' => 'Pengaturan dan FTP'],
    ];
@endphp

<div @class(['lg:grid lg:grid-cols-[248px_1fr]', 'lg:h-screen lg:overflow-hidden' => $fullHeight])>
    {{-- Sidebar: terang seperti Google Drive --}}
    <aside class="border-b border-garis-g bg-white text-arang lg:sticky lg:top-0 lg:flex lg:h-screen lg:flex-col lg:border-r lg:border-b-0">
        <div class="flex items-center justify-between px-5 py-5 lg:block">
            <a href="{{ route('home') }}" class="block rounded-md">
                <span class="flex items-center gap-2.5">
                    <svg viewBox="0 0 32 32" class="size-8 shrink-0" aria-hidden="true">
                        <rect x="3" y="7" width="26" height="20" rx="3" fill="#E8F0FE"/>
                        <path d="M3 10a3 3 0 013-3h7l3 3h10a3 3 0 013 3" fill="none" stroke="#AECBFA" stroke-width="2"/>
                        <path d="M10 18l4 4 8-9" fill="none" stroke="#1A73E8" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-[17px] font-bold text-arang">{{ config('casemix.app_name') }}</span>
                </span>
                <span class="block text-[11px] text-abu">by Enigma</span>
                <span class="mt-1 block text-xs text-abu">{{ config('casemix.hospital_name') }}</span>
            </a>
        </div>

        <nav class="space-y-0.5 pr-3 pb-4 lg:min-h-0 lg:flex-1 lg:overflow-y-auto" aria-label="Menu utama">
            @foreach ($nav as $item)
                @if (isset($item['role']) ? auth()->user()->hasRole($item['role']) : auth()->user()->can($item['can']))
                    @php $active = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class(['flex h-10 items-center gap-3 rounded-r-full pr-4 pl-6 text-sm font-medium transition-colors duration-150',
                               'bg-biru-muda text-biru-aktif' => $active,
                               'text-arang hover:bg-abu-muda' => ! $active])
                       @if($active) aria-current="page" @endif>
                        <svg class="size-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                        </svg>
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach

            @can('admin.manage')
                <p class="pt-5 pr-4 pb-1.5 pl-6 text-xs font-medium text-abu">Admin</p>
                @foreach ($admin as $item)
                    @php $active = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class(['flex h-9 items-center rounded-r-full pr-4 pl-6 text-sm transition-colors duration-150',
                               'bg-biru-muda font-medium text-biru-aktif' => $active,
                               'text-arang hover:bg-abu-muda' => ! $active])
                       @if($active) aria-current="page" @endif>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endcan
        </nav>

        <x-robot-worker class="hidden shrink-0 px-4 pb-3 lg:block" aria-hidden="true"/>

        <div class="shrink-0 border-t border-garis-g bg-white px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="grid size-9 place-items-center rounded-full bg-biru text-sm font-bold text-white">{{ auth()->user()->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-arang">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-abu">{{ auth()->user()->roleLabel() }}</p>
                </div>
                <a href="{{ route('account.password.edit') }}" class="rounded-full p-2 text-abu transition-colors duration-150 hover:bg-abu-muda hover:text-arang" title="Ganti password">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path stroke-linecap="round" d="M8 11V7a4 4 0 018 0v4"/></svg>
                    <span class="sr-only">Ganti password</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-full p-2 text-abu transition-colors duration-150 hover:bg-abu-muda hover:text-arang" title="Keluar">
                        <svg class="size-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17l5-5-5-5M20 12H9M12 21H6a2 2 0 01-2-2V5a2 2 0 012-2h6"/></svg>
                        <span class="sr-only">Keluar</span>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Konten --}}
    <main @class(['flex min-h-screen min-w-0 flex-col', 'lg:h-screen' => $fullHeight])>
        @php
            $sync = \App\Models\AppSetting::get(\App\Services\Verification\FolderSync::KEY_LAST);
            $syncAt = is_array($sync) && ! empty($sync['at']) ? \Illuminate\Support\Carbon::parse($sync['at'])->timezone(config('app.timezone'))->format('H:i') : null;
            $syncOk = (bool) ($sync['ok'] ?? true);
            $diDaftar = request()->routeIs('folders.index');
        @endphp
        <header class="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-3 border-b border-garis-g bg-gradient-to-r from-[#E8F0FE] via-white to-white px-4 shadow-[0_1px_2px_rgba(32,33,36,.05)] lg:px-8">
            <div class="pointer-events-none absolute inset-0 opacity-40 [background-image:radial-gradient(var(--color-garis-g)_1px,transparent_1px)] [background-size:14px_14px]" aria-hidden="true"></div>
            <p class="relative flex min-w-0 shrink items-center gap-2 text-[15px] font-semibold text-arang sm:max-w-[30%]">
                <svg class="size-5 shrink-0 text-biru" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                <span class="truncate">{{ $title ?? config('casemix.app_name') }}</span>
            </p>
            <div class="relative flex min-w-0 flex-1 justify-center">
                @can('folders.view')
                    <form method="GET" action="{{ route('folders.index') }}" role="search" class="w-full max-w-[520px]">
                        @if ($diDaftar)
                            @foreach (request()->except(['sep', 'page']) as $k => $v)
                                @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                            @endforeach
                        @endif
                        <div class="cari h-10 bg-white/90">
                            <button type="submit" class="cari-ikon" aria-label="Cari">
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5" stroke-linecap="round"/></svg>
                            </button>
                            <label for="cari-sep" class="sr-only">Cari No. SEP</label>
                            <input id="cari-sep" name="sep" type="search" value="{{ $diDaftar ? request()->query('sep') : '' }}" maxlength="30"
                                   class="cari-input num text-sm" placeholder="Cari No. SEP" autocomplete="off" spellcheck="false">
                        </div>
                    </form>
                @endcan
            </div>
            <div class="relative flex shrink-0 items-center gap-3">
                @if ($syncAt)
                    <span @class(['hidden items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium md:inline-flex',
                                  'border-sesuai/25 bg-sesuai-muda text-sesuai' => $syncOk,
                                  'border-kritis/25 bg-kritis-muda text-kritis' => ! $syncOk])>
                        <span @class(['size-1.5 rounded-full', 'bg-sesuai' => $syncOk, 'bg-kritis' => ! $syncOk])></span>
                        {{ $syncOk ? 'FTP tersinkron' : 'FTP gagal' }} {{ $syncAt }}
                    </span>
                @endif
                <span class="grid size-9 place-items-center rounded-full bg-biru text-sm font-bold text-white" title="{{ auth()->user()->name }}">{{ auth()->user()->initials() }}</span>
            </div>
        </header>
        <div @class(['mx-auto w-full max-w-[1320px] flex-1 px-5 py-6 lg:px-8 lg:py-8', 'flex min-h-0 flex-col lg:overflow-hidden' => $fullHeight])>
            <x-ai-budget-banner/>

            @if (session('ok') || session('warn') || $errors->any())
                <div class="mb-5 space-y-2" role="status">
                    @if (session('ok'))
                        <div data-flash class="flex items-start gap-3 rounded-md border border-sesuai/25 bg-sesuai-muda px-4 py-3 text-sm text-sesuai">
                            <span class="flex-1">{{ session('ok') }}</span>
                            <button type="button" data-dismiss class="text-sesuai/70 hover:text-sesuai" aria-label="Tutup">✕</button>
                        </div>
                    @endif
                    @if (session('warn'))
                        <div data-flash class="flex items-start gap-3 rounded-md border border-waspada/25 bg-waspada-muda px-4 py-3 text-sm text-waspada">
                            <span class="flex-1">{{ session('warn') }}</span>
                            <button type="button" data-dismiss class="opacity-70 hover:opacity-100" aria-label="Tutup">✕</button>
                        </div>
                    @endif
                    @if ($errors->any())
                        <div data-flash class="rounded-md border border-kritis/25 bg-kritis-muda px-4 py-3 text-sm text-kritis">
                            <p class="font-semibold">Periksa kembali isian berikut:</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif

            @if ($fullHeight)
                <div class="min-h-0 flex-1">{{ $slot }}</div>
            @else
                {{ $slot }}
            @endif
        </div>
        <footer class="mt-auto shrink-0 border-t border-garis-g px-5 py-3 text-center text-xs text-abu lg:px-8">
            CERMAT by Enigma · RS Serenapita · © 2026 Enigma Works Indonesia
        </footer>
    </main>
</div>
</body>
</html>
