{{-- Dropdown ringan berbasis <details>. Slot trigger (bawaan: tombol "⋯"); isi = tautan/tombol .dropdown-item. align: right|left --}}
@props(['align' => 'right', 'width' => 'w-48'])
<details {{ $attributes->class('relative inline-block text-left') }} data-dropdown>
    <summary class="inline-flex cursor-pointer list-none items-center [&::-webkit-details-marker]:hidden">
        @isset($trigger)
            {{ $trigger }}
        @else
            <span class="grid size-8 place-items-center rounded-lg text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5"><x-icon name="dots"/><span class="sr-only">Aksi lainnya</span></span>
        @endisset
    </summary>
    <div @class(['dropdown-panel absolute z-40 mt-2', $width, 'right-0' => $align === 'right', 'left-0' => $align !== 'right'])>
        {{ $slot }}
    </div>
</details>
