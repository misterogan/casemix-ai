@props(['name', 'label', 'hint' => null, 'required' => false])
<div {{ $attributes->only('class') }}>
    <label for="{{ $name }}" class="label">{{ $label }}@if($required)<span class="text-kritis"> *</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p class="hint">{{ $hint }}</p>@endif
    @error($name)<p class="field-error">{{ $message }}</p>@enderror
</div>
