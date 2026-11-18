{{-- Select dengan chevron sendiri (appearance-none). Atribut class diteruskan ke pembungkus untuk lebar. --}}
@props(['hint' => false, 'wrapper' => ''])
@php
    $name = $attributes->get('name');
    $id = $attributes->get('id', $name);
    $invalid = $name && $errors->has($name);
    $desc = trim(($hint ? "$id-hint " : '') . ($invalid ? "$id-error" : ''));
@endphp
<div class="relative {{ $wrapper }}">
    <select {{ $attributes->merge(['id' => $id])->class(['field', 'field-invalid' => $invalid]) }}
        @if ($desc) aria-describedby="{{ $desc }}" @endif @if ($invalid) aria-invalid="true" @endif>{{ $slot }}</select>
</div>
