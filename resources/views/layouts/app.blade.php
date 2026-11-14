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
<body class="min-h-screen">
@php
    $nav = [
        ['route' => 'dashboard', 'match' => 'dashboard', 'label' => 'Ringkasan', 'can' => 'claims.view',
         'icon' => 'M3 12l9-8 9 8M5 10v10h5v-6h4v6h5V10'],
        ['route' => 'claims.index', 'match' => 'claims.*', 'label' => 'Berkas klaim', 'can' => 'claims.view',
         'icon' => 'M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z'],
        ['route' => 'imports.index', 'match' => 'imports.*', 'label' => 'Impor dari SIMRS', 'can' => 'claims.import',
         'icon' => 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2'],
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
    {{-- Sidebar --}}
    <aside class="bg-hutan text-white lg:sticky lg:top-0 lg:h-screen lg:overflow-y-auto">
        <div class="flex items-center justify-between px-5 py-5 lg:block">
            <a href="{{ route('dashboard') }}" class="block">
                <span class="flex items-center gap-2.5">
                    <svg viewBox="0 0 32 32" class="size-8 shrink-0" aria-hidden="true">
                        <rect x="3" y="7" width="26" height="20" rx="3" fill="#EADFC4"/>
                        <path d="M3 10a3 3 0 013-3h7l3 3h10a3 3 0 013 3" fill="none" stroke="#D8C89F" stroke-width="2"/>
                        <path d="M10 18l4 4 8-9" fill="none" stroke="#0E6B57" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="text-[17px] font-bold">{{ config('casemix.app_name') }}</span>
                </span>
                <span class="mt-1 block text-xs text-white/60">{{ config('casemix.hospital_name') }}</span>
            </a>
        </div>

        <nav class="space-y-0.5 px-3 pb-4" aria-label="Menu utama">
            @foreach ($nav as $item)
                @can($item['can'])
                    @php $active = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class(['flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium',
                               'bg-white/12 text-white' => $active,
                               'text-white/75 hover:bg-white/6 hover:text-white' => ! $active])
                       @if($active) aria-current="page" @endif>
                        <svg class="size-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/>
                        </svg>
                        {{ $item['label'] }}
                    </a>
                @endcan
            @endforeach

            @can('admin.manage')
                <p class="px-3 pt-5 pb-1.5 text-xs font-medium text-white/45">Admin</p>
                @foreach ($admin as $item)
                    @php $active = request()->routeIs($item['match']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class(['block rounded-md px-3 py-1.5 text-sm',
                               'bg-white/12 text-white' => $active,
                               'text-white/70 hover:bg-white/6 hover:text-white' => ! $active])>
                        {{ $item['label'] }}
                    </a>
                @endforeach
            @endcan
        </nav>

        <div class="border-t border-white/10 px-5 py-4 lg:absolute lg:inset-x-0 lg:bottom-0 lg:bg-hutan">
            <div class="flex items-center gap-3">
                <span class="grid size-9 place-items-center rounded-full bg-manila text-sm font-bold text-hutan">{{ auth()->user()->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                    <p class="truncate text-xs text-white/60">{{ auth()->user()->roleLabel() }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-md p-1.5 text-white/60 hover:bg-white/10 hover:text-white" title="Keluar">
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
