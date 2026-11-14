{{-- Satu temuan sebagai kartu: garis warna di kiri, judul, uraian, tautan ke dokumen. --}}
@php
    $t = $f['tingkat'] ?? 'perhatian';
    $garis = ['kritis' => 'border-l-kritis', 'perhatian' => 'border-l-waspada', 'sesuai' => 'border-l-sesuai'][$t] ?? 'border-l-waspada';
    $warna = ['kritis' => 'text-kritis', 'perhatian' => 'text-waspada', 'sesuai' => 'text-sesuai'][$t] ?? 'text-waspada';
    $label = ['kritis' => 'Kritis', 'perhatian' => 'Perhatian', 'sesuai' => 'Sesuai'][$t] ?? 'Perhatian';
    $url = $docLink($f['dokumen'] ?? null, $f['halaman'] ?? null);
@endphp
<li class="kartu-temuan {{ $garis }}">
    <p class="font-semibold text-arang">{{ $f['judul'] }}</p>
    @if (! empty($f['uraian']))<p class="mt-1 max-w-[75ch] text-sm leading-relaxed text-abu">{{ $f['uraian'] }}</p>@endif
    @if (! empty($f['dokumen']))
        <p class="mt-2 text-sm">
            @if ($url)
                <a href="{{ $url }}" target="_blank" rel="noopener" class="tautan-g">{{ $f['dokumen'] }}@if (! empty($f['halaman'])) · hal. {{ $f['halaman'] }}@endif ↗</a>
            @else
                <span class="text-abu">{{ $f['dokumen'] }}@if (! empty($f['halaman'])) · hal. {{ $f['halaman'] }}@endif</span>
            @endif
        </p>
    @endif
    <p class="mt-1.5 text-xs text-abu">
        <span class="font-semibold {{ $warna }}">{{ $label }}</span> · {{ $f['kategori'] ?? 'Lainnya' }} · {{ ($f['sumber'] ?? '') === 'aturan' ? 'Aturan E-Klaim' : 'AI' }}
    </p>
</li>
