@props(['title', 'text' => null, 'action' => null, 'href' => null])
<div {{ $attributes->class('px-6 py-12 text-center') }}>
    <p class="font-semibold text-ink">{{ $title }}</p>
    @if ($text)<p class="mx-auto mt-1 max-w-md text-sm text-ink-muted">{{ $text }}</p>@endif
    @if ($action && $href)
        <div class="mt-4"><a href="{{ $href }}" class="btn-primary">{{ $action }}</a></div>
    @elseif (trim($slot))
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>
