@props(['name', 'label', 'hint' => null, 'required' => false, 'for' => null, 'aside' => null])
@php($id = $for ?? $name)
<div {{ $attributes->only('class') }}>
    <div class="mb-1.5 flex items-baseline justify-between gap-2">
        <label for="{{ $id }}" class="text-sm font-medium text-gray-700 dark:text-gray-400">{{ $label }}@if($required)<span class="text-error-500" aria-hidden="true"> *</span>@endif</label>
        {{ $aside }}
    </div>
    @if ($hint)<p id="{{ $id }}-hint" class="-mt-0.5 mb-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>@endif
    {{ $slot }}
    @error($name)<p id="{{ $id }}-error" class="field-error" role="alert">{{ $message }}</p>@enderror
</div>
