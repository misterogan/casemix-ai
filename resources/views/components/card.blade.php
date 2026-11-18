{{-- Kartu: putih, border gray-200, radius 16px. Prop/slot opsional: title, subtitle, actions, header. :padding="false" untuk isi tabel. --}}
@props(['title' => null, 'subtitle' => null, 'padding' => true])
<div {{ $attributes->class('rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[.03]') }}>
    @if ($title || isset($actions) || isset($header))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
            @isset($header)
                {{ $header }}
            @else
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-white/90">{{ $title }}</h3>
                    @if ($subtitle)<p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>@endif
                </div>
            @endisset
            @isset($actions)<div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['p-5 sm:p-6' => $padding])>{{ $slot }}</div>
</div>
