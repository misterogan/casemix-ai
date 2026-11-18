{{-- Tombol seragam: radius 6px, tinggi 40px. variant: primary | secondary | ghost | danger. href = tautan bergaya tombol. --}}
@props(['variant' => 'primary', 'type' => 'button', 'href' => null])
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class("btn-$variant") }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class("btn-$variant") }}>{{ $slot }}</button>
@endif
