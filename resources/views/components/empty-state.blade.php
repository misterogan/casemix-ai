@props(['title', 'text' => null, 'action' => null, 'href' => null, 'icon' => 'inbox'])
<div {{ $attributes->class('flex flex-col items-center px-6 py-12 text-center') }}>
    <span class="mb-4 grid size-14 place-items-center rounded-2xl bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"><x-icon :name="$icon" class="size-7"/></span>
    <p class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</p>
    @if ($text)<p class="mx-auto mt-1 max-w-md text-sm text-gray-500 dark:text-gray-400">{{ $text }}</p>@endif
    @if ($action && $href)
        <div class="mt-5"><a href="{{ $href }}" class="btn-primary">{{ $action }}</a></div>
    @elseif (trim($slot))
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
