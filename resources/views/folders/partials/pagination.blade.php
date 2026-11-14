{{-- Paginasi sederhana gaya Google: Sebelumnya · 1 2 3 · Berikutnya --}}
@if ($paginator->hasPages())
    <nav class="halaman-g" aria-label="Navigasi halaman">
        @unless ($paginator->onFirstPage())
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev" data-partial class="halaman-g-ujung">‹ Sebelumnya</a>
        @endunless

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="halaman-g-no text-abu" aria-hidden="true">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="halaman-g-no num font-semibold text-arang" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" data-partial class="halaman-g-no num text-tautan hover:bg-abu-muda" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next" data-partial class="halaman-g-ujung">Berikutnya ›</a>
        @endif
    </nav>
@endif
