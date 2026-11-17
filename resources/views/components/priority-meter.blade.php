@props(['value' => 0])
@php
    $v = max(0, min(100, (int) round($value)));
    $tone = $v >= 60 ? 'bg-triage-red' : ($v >= 30 ? 'bg-triage-amber' : 'bg-triage-green');
@endphp
<span {{ $attributes->class('inline-flex items-center gap-2') }}>
    <span class="relative h-1.5 w-16 overflow-hidden rounded-full bg-line" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $v }}" aria-label="Prioritas">
        <span class="absolute inset-y-0 left-0 rounded-full {{ $tone }}" style="width: {{ $v }}%"></span>
    </span>
    <span class="tabular text-xs font-semibold text-ink">{{ $v }}</span>
</span>
