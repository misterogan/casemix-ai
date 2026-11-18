{{-- Kartu statistik: label, angka besar, ikon 44px dalam kotak berwarna, chip tren opsional. tone: brand|success|warning|error|info|gray --}}
@props(['label', 'value', 'icon' => null, 'tone' => 'brand', 'trend' => null, 'trendLabel' => 'dari bulan lalu', 'href' => null, 'hint' => null])
@php
    $kotak = [
        'brand' => 'bg-brand-50 text-brand-500 dark:text-brand-400',
        'success' => 'bg-success-50 text-success-600 dark:text-success-500',
        'warning' => 'bg-warning-50 text-warning-600 dark:text-orange-400',
        'error' => 'bg-error-50 text-error-600 dark:text-error-500',
        'info' => 'bg-info-50 text-info-600 dark:text-info-500',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    ][$tone] ?? 'bg-gray-100 text-gray-700';
    $naik = $trend !== null && (float) $trend >= 0;
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->class(['block rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[.03]', 'transition-colors hover:border-brand-300 dark:hover:border-brand-800' => $href]) }}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</p>
            <p class="num mt-2 text-[28px] leading-9 font-semibold text-gray-800 dark:text-white/90" data-stat>{{ $value }}</p>
        </div>
        @if ($icon)
            <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $kotak }}"><x-icon :name="$icon" class="size-6"/></span>
        @endif
    </div>
    @if ($trend !== null || $hint)
        <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
            @if ($trend !== null)
                <span @class(['badge', 'tone-ok' => $naik, 'tone-crit' => ! $naik])>
                    <x-icon :name="$naik ? 'arrow-up' : 'arrow-down'" class="size-3"/>
                    <span class="num">{{ str_replace('.', ',', (string) abs((float) $trend)) }}%</span>
                </span>
                <span class="text-gray-500 dark:text-gray-400">{{ $trendLabel }}</span>
            @endif
            @if ($hint)<span class="text-gray-500 dark:text-gray-400">{{ $hint }}</span>@endif
        </div>
    @endif
    {{ $slot }}
</{{ $tag }}>
