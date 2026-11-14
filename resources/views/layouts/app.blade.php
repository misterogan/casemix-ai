<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' · ' : '' }}{{ config('casemix.app_name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-latar">
@php
    // Menu utama: Koordinator membaca hasil verifikasi AI langsung dari folder FTP.
    // Menu berkas klaim, impor, dan ringkasan lama disembunyikan (datanya tetap ada).
    $nav = [
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

<div class="lg:grid lg:grid-cols-[248px_1fr]">
    {{-- Sidebar: terang seperti Google Drive --}}
    <aside class="border-b border-garis-g bg-white text-arang lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto lg:border-r lg:border-b-0">
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
                <span class="mt-1 block text-xs text-abu">{{ config('casemix.hospital_name') }}</span>
            </a>
        </div>

        <nav class="space-y-0.5 pr-3 pb-4 lg:pb-24" aria-label="Menu utama">
            @foreach ($nav as $item)
                @can($item['can'])
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
                @endcan
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

        <div class="border-t border-garis-g px-5 py-4 lg:absolute lg:inset-x-0 lg:bottom-0 lg:bg-white">
            <div class="flex items-center gap-3">
                <span class="grid size-9 place-items-center rounded-full bg-biru text-sm font-bold text-white">{{ auth()->user()->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-arang">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-abu">{{ auth()->user()->roleLabel() }}</p>
                </div>
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
    <main class="min-w-0">
        <div class="mx-auto max-w-[1320px] px-5 py-6 lg:px-8 lg:py-8">
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

            {{ $slot }}
        </div>
    </main>
</div>
</body>
</html>
