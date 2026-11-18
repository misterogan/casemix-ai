@props(['hint' => false])
@php
    $name = $attributes->get('name');
    $id = $attributes->get('id', $name);
    $invalid = $name && $errors->has($name);
    $desc = trim(($hint ? "$id-hint " : '') . ($invalid ? "$id-error" : ''));
@endphp
<textarea {{ $attributes->merge(['id' => $id, 'rows' => 3])->class(['field', 'field-invalid' => $invalid]) }}
    @if ($desc) aria-describedby="{{ $desc }}" @endif @if ($invalid) aria-invalid="true" @endif>{{ $slot }}</textarea>
