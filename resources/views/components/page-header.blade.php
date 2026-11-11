@props(['title', 'subtitle' => null, 'back' => null])
<header class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-2 inline-flex items-center gap-1 text-sm text-tinta-3 hover:text-giok">
                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 18l-6-6 6-6"/></svg>
                Kembali
            </a>
        @endif
        <h1 class="text-[26px] leading-tight font-bold tracking-tight text-tinta">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-sm text-tinta-2">{{ $subtitle }}</p>
        @endif
        {{ $meta ?? '' }}
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</header>
