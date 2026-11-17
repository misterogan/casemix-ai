@props(['status' => 'belum'])
@php
    $map = [
        'siap' => ['Siap', 'bg-triage-green-soft', 'bg-triage-green'],
        'tinjau' => ['Tinjau', 'bg-triage-amber-soft', 'bg-triage-amber'],
        'blokir' => ['Blokir', 'bg-triage-red-soft', 'bg-triage-red'],
        'belum' => ['Belum diperiksa', 'bg-paper border border-line', 'bg-ink-muted'],
    ];
    [$label, $bg, $dot] = $map[$status] ?? $map['belum'];
@endphp
<span {{ $attributes->class(["inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold whitespace-nowrap text-ink", $bg]) }}>
    <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $slot->isEmpty() ? $label : $slot }}
</span>
