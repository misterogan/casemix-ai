{{-- Input teks/tanggal/angka: tinggi 40px, fokus brand, error merah. hint=true menghubungkan aria-describedby ke "{id}-hint". --}}
@props(['hint' => false])
@php
    $name = $attributes->get('name');
    $id = $attributes->get('id', $name);
    $invalid = $name && $errors->has($name);
    $desc = trim(($hint ? "$id-hint " : '') . ($invalid ? "$id-error" : ''));
@endphp
<input {{ $attributes->merge(['id' => $id, 'type' => 'text'])->class(['field', 'field-invalid' => $invalid]) }}
    @if ($desc) aria-describedby="{{ $desc }}" @endif @if ($invalid) aria-invalid="true" @endif>
