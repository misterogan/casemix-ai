@props(['size' => 120])
@php($gif = config('casemix.loader_gif'))
@if ($gif)
    <img src="{{ asset($gif) }}" alt="" width="{{ $size }}" height="{{ $size }}" {{ $attributes->merge(['class' => 'robot-loader object-contain']) }} data-robot-gif>
@else
<svg {{ $attributes->merge(['class' => 'robot-loader']) }} width="{{ $size }}" height="{{ $size }}" viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
    <g class="rl-gigi"><circle cx="100" cy="22" r="7" fill="none" stroke="currentColor" stroke-width="4" stroke-dasharray="3 2.5" class="text-gray-400"/><circle cx="100" cy="22" r="2.5" class="fill-gray-400"/></g>
    <line x1="44" y1="14" x2="44" y2="24" class="stroke-gray-400" stroke-width="2.5" stroke-linecap="round"/>
    <circle class="rl-antena fill-brand-500" cx="44" cy="11" r="4"/>
    <rect x="24" y="24" width="40" height="30" rx="10" class="fill-brand-500"/>
    <rect x="30" y="31" width="28" height="15" rx="6" class="fill-white dark:fill-gray-900"/>
    <g class="rl-mata fill-brand-700 dark:fill-brand-300"><circle cx="38" cy="38.5" r="3"/><circle cx="50" cy="38.5" r="3"/></g>
    <rect x="22" y="58" width="44" height="40" rx="8" class="fill-gray-200 dark:fill-gray-700"/>
    <rect x="29" y="64" width="30" height="22" rx="4" class="fill-white dark:fill-gray-900"/>
    <g class="fill-brand-500"><rect class="rl-bar rl-b1" x="33" y="70" width="5" height="13" rx="1"/><rect class="rl-bar rl-b2" x="41.5" y="70" width="5" height="13" rx="1"/><rect class="rl-bar rl-b3" x="50" y="70" width="5" height="13" rx="1"/></g>
    <rect x="28" y="98" width="10" height="10" rx="3" class="fill-gray-400"/><rect x="50" y="98" width="10" height="10" rx="3" class="fill-gray-400"/>
    <rect x="80" y="62" width="28" height="36" rx="3" class="fill-white stroke-gray-300 dark:fill-gray-800 dark:stroke-gray-600" stroke-width="1.5"/>
    <g class="stroke-gray-300 dark:stroke-gray-600" stroke-width="2" stroke-linecap="round"><path d="M85 70h18M85 76h14M85 82h18M85 88h10"/></g>
    <g class="rl-lengan">
        <path d="M66 70 L80 76" class="stroke-gray-400" stroke-width="5" stroke-linecap="round"/>
        <line x1="83" y1="78" x2="86" y2="72" class="stroke-gray-500" stroke-width="3" stroke-linecap="round"/>
        <circle cx="90" cy="66" r="7" class="fill-brand-100/60 stroke-brand-600 dark:fill-brand-500/20 dark:stroke-brand-300" stroke-width="2.5"/>
    </g>
</svg>
@endif
