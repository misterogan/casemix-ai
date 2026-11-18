@props(['title', 'subtitle' => null, 'back' => null, 'crumbs' => null])
<header class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-1.5 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-brand-500 dark:text-gray-400">
                <x-icon name="chevron-left" class="size-4"/> Kembali
            </a>
        @endif
        <h1 class="text-xl font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
        {{ $meta ?? '' }}
    </div>
    <div class="flex flex-wrap items-center gap-3">
        @isset($actions)
            <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
        <nav aria-label="Breadcrumb" class="hidden sm:block">
            <ol class="flex items-center gap-1.5 text-sm">
                <li><a href="{{ route('home') }}" class="inline-flex items-center gap-1.5 text-gray-500 hover:text-brand-500 dark:text-gray-400">Beranda <x-icon name="chevron-right" class="size-4"/></a></li>
                @foreach ((array) $crumbs as $label => $url)
                    <li><a href="{{ $url }}" class="inline-flex items-center gap-1.5 text-gray-500 hover:text-brand-500 dark:text-gray-400">{{ $label }} <x-icon name="chevron-right" class="size-4"/></a></li>
                @endforeach
                <li class="max-w-[16rem] truncate text-gray-800 dark:text-white/90" aria-current="page">{{ $title }}</li>
            </ol>
        </nav>
    </div>
</header>
