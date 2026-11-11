@props(['status'])
<span {{ $attributes->class(['badge', $status->tone()]) }}>{{ $status->label() }}</span>
