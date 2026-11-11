@props(['level'])
@php $s = \App\Enums\Severity::tryFrom($level) ?? \App\Enums\Severity::Perhatian; @endphp
<span {{ $attributes->class(['badge', $s->tone()]) }}>{{ $s->label() }}</span>
