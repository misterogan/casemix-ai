{{-- Tombol seragam: radius 8px, tinggi 40px (size="sm" 32px). variant: primary | secondary | ghost | danger. href = tautan bergaya tombol. icon = nama x-icon. --}}
@props(['variant' => 'primary', 'type' => 'button', 'href' => null, 'size' => null, 'icon' => null])
@php $cls = ["btn-$variant", 'btn-sm' => $size === 'sm']; @endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($cls) }}>@if($icon)<x-icon :name="$icon" class="size-4"/>@endif{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($cls) }}>@if($icon)<x-icon :name="$icon" class="size-4"/>@endif{{ $slot }}</button>
@endif
