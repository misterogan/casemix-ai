{{-- Panel. Opsional: step (nomor) + title → kepala "1 · Judul" dengan isi berpadding 24px. --}}
@props(['step' => null, 'title' => null])
<div {{ $attributes->class('rounded-panel border border-line bg-white') }}>
    @if ($title)
        @php($tid = 'panel-' . \Illuminate\Support\Str::slug($title))
        <div class="p-6" role="group" aria-labelledby="{{ $tid }}">
            <h2 id="{{ $tid }}" class="mb-4 flex items-center gap-2 text-base font-semibold text-tinta">
                @if ($step)<span class="inline-flex size-6 items-center justify-center rounded-full bg-brand-soft text-xs font-bold text-brand">{{ $step }}</span><span class="sr-only">·</span>@endif
                {{ $title }}
            </h2>
            {{ $slot }}
        </div>
    @else
        {{ $slot }}
    @endif
</div>
