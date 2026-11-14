{{-- Daftar hasil verifikasi berkas. Dipakai halaman penuh dan respons pencarian langsung (AJAX). --}}
@php
    $tabs = ['belum' => 'Belum diperiksa AI'];
    if (($counts['proses'] ?? 0) > 0 || $state === 'proses') $tabs['proses'] = 'Sedang diperiksa';
    $tabs += ['temuan' => 'Ada temuan', 'lengkap' => 'Tidak ada temuan', 'berubah' => 'File berubah', 'gagal' => 'Gagal'];
    $withQ = $q !== '' ? ['q' => $q] : [];
@endphp
<nav class="tabs-g" aria-label="Filter status">
    <a href="{{ route('folders.index', $withQ) }}" data-partial @class(['tab-g', 'is-aktif' => ! $state]) @if (! $state) aria-current="page" @endif>
        Semua <span class="num text-xs">{{ $total }}</span>
    </a>
    @foreach ($tabs as $k => $label)
        <a href="{{ route('folders.index', $withQ + ['status' => $k]) }}" data-partial @class(['tab-g', 'is-aktif' => $state === $k]) @if ($state === $k) aria-current="page" @endif>
            {{ $label }} <span class="num text-xs">{{ $counts[$k] ?? 0 }}</span>
        </a>
    @endforeach
</nav>

<p class="num mt-3 text-sm text-abu" aria-live="polite">
    {{ number_format($folders->total(), 0, ',', '.') }} folder{{ $q !== '' ? ' ditemukan' : '' }}
</p>

<div data-list>
    @if ($folders->isEmpty())
        <div class="py-12 text-center">
            @if ($q !== '' || $state)
                <p class="text-arang">Tidak ada folder yang cocok{{ $q !== '' ? ' dengan "' . $q . '"' : '' }}.</p>
                <p class="mt-1 text-sm text-abu">Coba kata kunci atau filter lain. Periksa ejaan nama, atau cari dengan No. RM / No. SEP.</p>
            @else
                <p class="text-arang">Belum ada folder.</p>
                <p class="mt-1 text-sm text-abu">Folder pasien dari FTP DHealth akan muncul setelah sinkronisasi otomatis berikutnya.</p>
            @endif
        </div>
    @else
        <ol class="mt-1">
            @foreach ($folders as $f)
                @include('folders.partials.result-item', ['f' => $f])
            @endforeach
        </ol>
        {{ $folders->links('folders.partials.pagination') }}
    @endif
</div>
