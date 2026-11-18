{{-- Badge pill 12px medium. variant: success|warning|error|info|gray|brand --}}
@props(['variant' => 'gray'])
@php $tone = ['success' => 'tone-ok', 'warning' => 'tone-warn', 'error' => 'tone-crit', 'info' => 'tone-info', 'gray' => 'tone-neutral', 'brand' => 'tone-brand'][$variant] ?? 'tone-neutral'; @endphp
<span {{ $attributes->class(['badge', $tone]) }}>{{ $slot }}</span>
