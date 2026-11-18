{{-- Tab bergaris bawah brand. items: [['label' => …, 'href' => …, 'active' => bool, 'count' => int|null], …] --}}
@props(['items' => [], 'label' => 'Tab'])
<nav {{ $attributes->class('tabs-g') }} aria-label="{{ $label }}">
    @foreach ($items as $t)
        <a href="{{ $t['href'] }}" @class(['tab-g', 'is-aktif' => $t['active'] ?? false]) @if($t['active'] ?? false) aria-current="page" @endif>
            {{ $t['label'] }}
            @if (isset($t['count']))<span class="badge tone-neutral num">{{ $t['count'] }}</span>@endif
        </a>
    @endforeach
    {{ $slot }}
</nav>
