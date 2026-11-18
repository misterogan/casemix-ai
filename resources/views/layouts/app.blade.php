@props(['title' => null, 'fullHeight' => false])
<!DOCTYPE html>
<html lang="id" @class(['lg:h-full lg:overflow-hidden' => $fullHeight])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title . ' · ' : '' }}{{ config('casemix.app_name') }}</title>
    <script>
        {{-- Tema & sidebar diterapkan sebelum render agar tidak berkedip --}}
        (function () {
            var d = document.documentElement, t = null, c = null;
            try { t = localStorage.getItem('tema'); c = localStorage.getItem('sidebar-ciut'); } catch (e) {}
            if (t === 'dark' || (t === null && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) d.classList.add('dark');
            if (c === '1') d.classList.add('sidebar-ciut');
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['min-h-screen bg-gray-50 text-gray-800 dark:bg-gray-950 dark:text-gray-100', 'lg:h-full lg:overflow-hidden' => $fullHeight])>
{{-- Indikator pindah halaman (resources/js/page-loader.js) --}}
<div class="muat-bilah" data-muat-bilah aria-hidden="true"></div>
<div class="muat-overlay" data-muat-overlay data-page-loader role="status" aria-live="polite" hidden>
    <x-robot-loader :size="120" />
    <p class="text-sm font-medium text-gray-700 dark:text-gray-200" data-muat-teks>Menyiapkan halaman…</p>
    <p class="text-sm font-medium text-gray-700 dark:text-gray-200" data-muat-tenang>Memuat…</p>
</div>
@php
    $u = auth()->user();
    $koor = $u->hasRole('koordinator');
    $tab = request()->query('tab');
    $grup = [
        'Menu' => [
            ['route' => 'control.index', 'match' => 'control.*', 'label' => 'Dashboard', 'icon' => 'dashboard', 'show' => $koor],
            ['route' => 'work-queue.index', 'match' => 'work-queue.*', 'label' => 'Antrean kerja', 'icon' => 'queue', 'show' => $koor],
            ['route' => 'folders.index', 'match' => 'folders.*', 'label' => 'Verifikasi berkas', 'icon' => 'folder-check', 'show' => $u->can('folders.view')],
            ['route' => 'bpjs.index', 'match' => 'bpjs.*', 'label' => 'Klaim BPJS', 'icon' => 'card', 'show' => $koor, 'sub' => [
                ['url' => route('bpjs.index', ['tab' => 'ringkasan']), 'label' => 'Ringkasan', 'active' => request()->routeIs('bpjs.index') && ! in_array($tab, ['klaim', 'sep'], true)],
                ['url' => route('bpjs.index', ['tab' => 'klaim']), 'label' => 'Daftar klaim', 'active' => (request()->routeIs('bpjs.index') && $tab === 'klaim') || request()->routeIs('bpjs.klaim.*')],
                ['url' => route('bpjs.index', ['tab' => 'sep']), 'label' => 'SEP terbit', 'active' => request()->routeIs('bpjs.index') && $tab === 'sep'],
            ]],
            ['route' => 'manual.create', 'match' => 'manual.*', 'label' => 'Unggah manual', 'icon' => 'upload', 'show' => $koor, 'baru' => true],
        ],
        'Referensi' => [
            ['route' => 'admin.regulations.index', 'match' => 'admin.regulations.*', 'label' => 'Acuan regulasi', 'icon' => 'book', 'show' => $u->can('admin.manage')],
        ],
        'Sistem' => [
            ['route' => 'audit.index', 'match' => 'audit.*', 'label' => 'Riwayat aktivitas', 'icon' => 'history', 'show' => $u->can('audit.view')],
            ['route' => 'admin.users.index', 'match' => ['admin.users.*', 'admin.roles.*', 'admin.providers.*', 'admin.budget.*', 'admin.settings.*'], 'label' => 'Administrasi', 'icon' => 'settings', 'show' => $u->can('admin.manage'), 'sub' => [
                ['url' => route('admin.users.index'), 'label' => 'Pengguna', 'active' => request()->routeIs('admin.users.*')],
                ['url' => route('admin.roles.index'), 'label' => 'Peran dan hak akses', 'active' => request()->routeIs('admin.roles.*')],
                ['url' => route('admin.providers.index'), 'label' => 'Penyedia AI', 'active' => request()->routeIs('admin.providers.*')],
                ['url' => route('admin.budget.index'), 'label' => 'Anggaran AI', 'active' => request()->routeIs('admin.budget.*')],
                ['url' => route('admin.settings.index'), 'label' => 'Pengaturan dan FTP', 'active' => request()->routeIs('admin.settings.*')],
            ]],
        ],
    ];
    $sync = \App\Models\AppSetting::get(\App\Services\Verification\FolderSync::KEY_LAST);
    $syncAt = is_array($sync) && ! empty($sync['at']) ? \Illuminate\Support\Carbon::parse($sync['at'])->timezone(config('app.timezone'))->format('H:i') : null;
    $syncOk = (bool) ($sync['ok'] ?? true);
    $diDaftar = request()->routeIs('folders.index');
    $diKlaim = request()->routeIs('bpjs.index') && $tab === 'klaim';
@endphp

{{-- Overlay drawer HP --}}
<div class="fixed inset-0 z-40 hidden bg-gray-900/50 lg:hidden" data-sidebar-overlay aria-hidden="true"></div>

{{-- Sidebar --}}
<aside id="sidebar" class="sidebar fixed inset-y-0 left-0 z-50 flex w-[290px] -translate-x-full flex-col border-r border-gray-200 bg-white px-5 transition-[transform,width] duration-200 ease-out lg:translate-x-0 dark:border-gray-800 dark:bg-gray-900" data-sidebar aria-label="Navigasi">
    <div class="ciut-tengah flex h-[72px] shrink-0 items-center justify-between gap-2">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5 rounded-lg">
            <svg viewBox="0 0 32 32" class="size-8 shrink-0" aria-hidden="true">
                <rect width="32" height="32" rx="8" fill="#465FFF"/>
                <path d="M9 16.5l4.5 4.5L23 11.5" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <span class="ciut-sembunyi leading-tight">
                <span class="block text-lg font-semibold text-gray-900 dark:text-white">{{ config('casemix.app_name') }}</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ config('casemix.hospital_name') }}</span>
            </span>
        </a>
        <button type="button" class="grid size-9 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 lg:hidden dark:text-gray-400 dark:hover:bg-white/5" data-sidebar-tutup aria-label="Tutup menu">
            <x-icon name="x"/>
        </button>
    </div>

    <nav class="-mx-1 min-h-0 flex-1 overflow-y-auto px-1 pt-4 pb-6" aria-label="Menu utama">
        @foreach ($grup as $judul => $items)
            @php $items = array_values(array_filter($items, fn ($i) => $i['show'])); @endphp
            @continue(! $items)
            <h2 class="ciut-sembunyi mb-3 text-xs font-medium tracking-wide text-gray-400 uppercase {{ $loop->first ? '' : 'mt-6' }}">{{ $judul }}</h2>
            @unless ($loop->first)<div class="mt-6 hidden border-t border-gray-200 [.sidebar-ciut_&]:lg:block dark:border-gray-800" aria-hidden="true"></div>@endunless
            <ul class="space-y-1">
                @foreach ($items as $item)
                    @php $active = request()->routeIs(...(array) $item['match']); @endphp
                    <li>
                        @if (! empty($item['sub']))
                            @php $subId = 'sub-' . \Illuminate\Support\Str::slug($item['label']); @endphp
                            <button type="button" @class(['menu-item w-full', 'is-aktif' => $active]) data-submenu aria-expanded="{{ $active ? 'true' : 'false' }}" aria-controls="{{ $subId }}" title="{{ $item['label'] }}">
                                <x-icon :name="$item['icon']"/>
                                <span class="ciut-sembunyi">{{ $item['label'] }}</span>
                                <x-icon name="chevron-down" class="menu-chevron ciut-sembunyi"/>
                            </button>
                            <div id="{{ $subId }}" @class(['menu-sub ciut-sembunyi', 'is-buka' => $active])>
                                <div><ul class="mt-1 ml-9 space-y-1">
                                    @foreach ($item['sub'] as $s)
                                        <li><a href="{{ $s['url'] }}" @class(['menu-sub-item', 'is-aktif' => $s['active']]) @if($s['active']) aria-current="page" @endif>{{ $s['label'] }}</a></li>
                                    @endforeach
                                </ul></div>
                            </div>
                        @else
                            <a href="{{ route($item['route']) }}" @class(['menu-item', 'is-aktif' => $active]) @if($active) aria-current="page" @endif title="{{ $item['label'] }}">
                                <x-icon :name="$item['icon']"/>
                                <span class="ciut-sembunyi">{{ $item['label'] }}</span>
                                @if (! empty($item['baru']))<span class="ciut-sembunyi chip chip-baru ml-auto">Baru</span>@endif
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    <x-robot-worker class="ciut-sembunyi hidden shrink-0 pb-4 lg:block" aria-hidden="true"/>
</aside>

{{-- Konten --}}
<div @class(['konten-utama flex min-h-screen min-w-0 flex-col transition-[padding] duration-200 ease-out lg:pl-[290px]', 'lg:h-screen lg:overflow-hidden' => $fullHeight])>
    <header class="sticky top-0 z-30 flex h-[72px] shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 lg:px-6 dark:border-gray-800 dark:bg-gray-900">
        <button type="button" class="grid size-10 shrink-0 place-items-center rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-100 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5" data-sidebar-toggle aria-controls="sidebar" aria-label="Ciutkan atau buka menu">
            <x-icon name="menu"/>
        </button>

        <div class="min-w-0 flex-1">
            @if ($koor || $u->can('folders.view'))
                <form method="GET" action="{{ $koor && ! $diDaftar ? route('bpjs.index') : route('folders.index') }}" role="search" class="hidden w-full max-w-[430px] sm:block" data-cari-global>
                    @if ($koor && ! $diDaftar)
                        <input type="hidden" name="tab" value="klaim">
                    @elseif ($diDaftar)
                        @foreach (request()->except(['sep', 'page']) as $k => $v)
                            @if (is_string($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
                        @endforeach
                    @endif
                    <label for="cari-sep" class="sr-only">Cari SEP, No. RM, nama</label>
                    <div class="relative">
                        <x-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-gray-500 dark:text-gray-400"/>
                        <input id="cari-sep" name="{{ $koor && ! $diDaftar ? 'q' : 'sep' }}" type="search" maxlength="{{ $koor && ! $diDaftar ? 100 : 30 }}"
                               value="{{ $diDaftar ? request()->query('sep') : ($diKlaim ? request()->query('q') : '') }}"
                               class="h-11 w-full rounded-lg border border-gray-200 bg-transparent py-2.5 pr-16 pl-12 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:ring-[3px] focus:ring-brand-500/20 focus:outline-none dark:border-gray-800 dark:bg-white/[.03] dark:text-white/90 dark:placeholder:text-white/30"
                               placeholder="Cari SEP, No. RM, nama…" autocomplete="off" spellcheck="false">
                        <kbd class="pointer-events-none absolute top-1/2 right-2.5 inline-flex -translate-y-1/2 items-center gap-0.5 rounded-lg border border-gray-200 bg-gray-50 px-[7px] py-[4.5px] text-xs text-gray-500 dark:border-gray-800 dark:bg-white/[.03] dark:text-gray-400" data-cari-kbd>⌘K</kbd>
                    </div>
                </form>
            @endif
        </div>

        <div class="flex shrink-0 items-center gap-2 sm:gap-3">
            @if ($syncAt)
                <span @class(['badge hidden md:inline-flex', 'tone-ok' => $syncOk, 'tone-crit' => ! $syncOk])>
                    <span @class(['size-1.5 rounded-full', 'bg-success-500' => $syncOk, 'bg-error-500' => ! $syncOk])></span>
                    {{ $syncOk ? 'FTP tersinkron' : 'FTP gagal' }} <span class="num">{{ $syncAt }}</span>
                </span>
            @endif

            <button type="button" class="relative grid size-11 place-items-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white" data-tema-toggle aria-label="Ganti mode terang/gelap">
                <x-icon name="moon" class="size-5 dark:hidden"/>
                <x-icon name="sun" class="hidden size-5 dark:block"/>
            </button>

            @if ($koor)
                @php $belumDibaca = $u->unreadNotifications()->count(); @endphp
                <details class="relative" data-lonceng data-dropdown>
                    <summary class="relative grid size-11 cursor-pointer list-none place-items-center rounded-lg border border-gray-200 bg-white text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/5 [&::-webkit-details-marker]:hidden" aria-label="Notifikasi, {{ $belumDibaca }} belum dibaca">
                        <x-icon name="bell"/>
                        @if ($belumDibaca)
                            <span class="absolute top-0.5 right-0 z-10 flex size-2.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-orange-400 opacity-75 motion-reduce:hidden"></span><span class="relative inline-flex size-2.5 rounded-full bg-orange-400"></span></span>
                            <span class="sr-only" data-lonceng-n>{{ $belumDibaca > 99 ? '99+' : $belumDibaca }}</span>
                        @endif
                    </summary>
                    <div class="dropdown-panel absolute right-0 z-40 mt-3 w-[min(350px,calc(100vw-2rem))] p-3">
                        <div class="mb-2 flex items-center justify-between border-b border-gray-100 px-1 pb-3 dark:border-gray-800">
                            <span class="text-base font-semibold text-gray-800 dark:text-white/90">Notifikasi</span>
                            @if ($belumDibaca)
                                <form method="POST" action="{{ route('notifications.read-all') }}">@csrf<button class="text-xs font-medium text-brand-500 hover:text-brand-600">Tandai semua dibaca</button></form>
                            @endif
                        </div>
                        <ul class="max-h-96 overflow-y-auto text-sm">
                            @forelse ($u->notifications()->latest()->limit(10)->get() as $n)
                                <li><form method="POST" action="{{ route('notifications.open', $n->id) }}">@csrf
                                    <button type="submit" @class(['block w-full rounded-lg px-3 py-2.5 text-left hover:bg-gray-100 dark:hover:bg-white/5', 'font-medium text-gray-800 dark:text-white/90' => ! $n->read_at, 'text-gray-500 dark:text-gray-400' => $n->read_at]) data-notif>
                                        {{ $n->data['pesan'] ?? 'Notifikasi' }}
                                        <span class="num block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $n->created_at->format('d/m/Y H:i') }}</span>
                                    </button>
                                </form></li>
                            @empty
                                <li class="px-3 py-3 text-gray-500 dark:text-gray-400">Belum ada notifikasi.</li>
                            @endforelse
                        </ul>
                    </div>
                </details>
            @endif

            <details class="relative" data-dropdown>
                <summary class="flex cursor-pointer list-none items-center gap-3 rounded-lg text-gray-700 dark:text-gray-400 [&::-webkit-details-marker]:hidden">
                    <span class="grid size-11 place-items-center rounded-full bg-brand-500 text-sm font-semibold text-white">{{ $u->initials() }}</span>
                    <span class="hidden text-left leading-tight md:block">
                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ $u->name }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $u->roleLabel() }}</span>
                    </span>
                    <x-icon name="chevron-down" class="hidden size-4 md:block"/>
                </summary>
                <div class="dropdown-panel absolute right-0 z-40 mt-3 w-64">
                    <div class="border-b border-gray-100 px-3 pt-1 pb-3 dark:border-gray-800">
                        <p class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">{{ $u->name }}</p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $u->roleLabel() }}</p>
                    </div>
                    <div class="space-y-0.5 py-2">
                        <a href="{{ route('account.password.edit') }}" class="dropdown-item"><x-icon name="user" class="size-5 text-gray-500"/> Profil &amp; password</a>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="border-t border-gray-100 pt-2 dark:border-gray-800">
                        @csrf
                        <button type="submit" class="dropdown-item"><x-icon name="logout" class="size-5 text-gray-500"/> Keluar</button>
                    </form>
                </div>
            </details>
        </div>
    </header>

    <main @class(['mx-auto w-full max-w-screen-2xl flex-1 p-4 md:p-6', 'flex min-h-0 flex-col lg:overflow-hidden' => $fullHeight])>
        <x-ai-budget-banner/>

        @if (session('ok') || session('warn') || $errors->any())
            <div class="mb-5 space-y-2" role="status">
                @if (session('ok'))
                    <div data-flash class="flex items-start gap-3 rounded-xl border border-success-500/30 bg-success-50 px-4 py-3 text-sm text-success-700 dark:text-success-500">
                        <x-icon name="check" class="mt-0.5 size-5 shrink-0"/>
                        <span class="flex-1">{{ session('ok') }}</span>
                        <button type="button" data-dismiss class="opacity-70 hover:opacity-100" aria-label="Tutup"><x-icon name="x" class="size-4"/></button>
                    </div>
                @endif
                @if (session('warn'))
                    <div data-flash class="flex items-start gap-3 rounded-xl border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:text-orange-400">
                        <x-icon name="alert" class="mt-0.5 size-5 shrink-0"/>
                        <span class="flex-1">{{ session('warn') }}</span>
                        <button type="button" data-dismiss class="opacity-70 hover:opacity-100" aria-label="Tutup"><x-icon name="x" class="size-4"/></button>
                    </div>
                @endif
                @if ($errors->any())
                    <div data-flash class="rounded-xl border border-error-500/30 bg-error-50 px-4 py-3 text-sm text-error-700 dark:text-error-500">
                        <p class="font-medium">Periksa kembali isian berikut:</p>
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
    </main>
    <footer class="mt-auto shrink-0 border-t border-gray-200 px-4 py-3 text-center text-xs text-gray-500 md:px-6 dark:border-gray-800 dark:text-gray-400">
        CERMAT by Enigma · RS Serenapita · © 2026 Enigma Works Indonesia
    </footer>
</div>
</body>
</html>
