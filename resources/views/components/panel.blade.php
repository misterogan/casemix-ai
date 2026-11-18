{{-- Panel. Opsional: step (nomor) + title → kepala "1 · Judul" dengan isi berpadding 24px. --}}
@props(['step' => null, 'title' => null])
<div {{ $attributes->class('rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[.03]') }}>
    @if ($title)
        @php($tid = 'panel-' . \Illuminate\Support\Str::slug($title))
        <div class="p-5 sm:p-6" role="group" aria-labelledby="{{ $tid }}">
            <h2 id="{{ $tid }}" class="mb-4 flex items-center gap-2 text-base font-semibold text-gray-800 dark:text-white/90">
                @if ($step)<span class="inline-flex size-6 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-500">{{ $step }}</span><span class="sr-only">·</span>@endif
                {{ $title }}
            </h2>
            {{ $slot }}
        </div>
    @else
        {{ $slot }}
    @endif
</div>
