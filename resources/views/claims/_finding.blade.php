@php $sev = \App\Enums\Severity::tryFrom($f['tingkat'] ?? '') ?? \App\Enums\Severity::Perhatian; @endphp
<li class="grid grid-cols-[6px_1fr] gap-4 px-5 py-3.5">
    <span @class(['rounded-full',
        'bg-kritis' => $sev === \App\Enums\Severity::Kritis,
        'bg-waspada' => $sev === \App\Enums\Severity::Perhatian,
        'bg-sesuai' => $sev === \App\Enums\Severity::Sesuai]) aria-hidden="true"></span>
    <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
            <p class="font-semibold">{{ $f['judul'] }}</p>
            <x-severity-badge :level="$f['tingkat']"/>
        </div>
        @if (! empty($f['uraian']))<p class="mt-1 max-w-[75ch] text-sm leading-relaxed text-tinta-2">{{ $f['uraian'] }}</p>@endif
        <p class="mt-1 text-xs text-tinta-3">
            {{ $f['kategori'] ?? 'Lainnya' }}
            @if (! empty($f['dokumen'])) · {{ $f['dokumen'] }} @endif
            · {{ ($f['sumber'] ?? '') === 'aturan' ? 'Aturan E-Klaim' : 'AI' }}
        </p>
    </div>
</li>
