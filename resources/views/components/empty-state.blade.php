@props(['title', 'text' => null])
<div class="px-6 py-12 text-center">
    <svg viewBox="0 0 64 48" class="mx-auto h-12 w-16" aria-hidden="true">
        <rect x="4" y="10" width="56" height="34" rx="4" fill="#EADFC4"/>
        <path d="M4 14a4 4 0 014-4h14l5 5h29a4 4 0 014 4" fill="none" stroke="#D8C89F" stroke-width="2.5"/>
    </svg>
    <p class="mt-3 font-semibold text-tinta">{{ $title }}</p>
    @if ($text)<p class="mx-auto mt-1 max-w-md text-sm text-tinta-2">{{ $text }}</p>@endif
    @if (trim($slot))<div class="mt-4">{{ $slot }}</div>@endif
</div>
