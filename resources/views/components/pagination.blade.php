@if ($paginator->hasPages())
<nav class="flex items-center justify-between gap-3 border-t border-garis px-4 py-3 text-sm" aria-label="Halaman">
    <p class="text-tinta-3">
        Menampilkan <span class="num font-medium text-tinta">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
        dari <span class="num font-medium text-tinta">{{ $paginator->total() }}</span>
    </p>
    <div class="flex items-center gap-1">
        @if ($paginator->onFirstPage())
            <span class="btn-ghost opacity-40">Sebelumnya</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn-ghost" rel="prev">Sebelumnya</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-2 text-tinta-3">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="num grid size-8 place-items-center rounded-md bg-hutan font-semibold text-white" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="num grid size-8 place-items-center rounded-md text-tinta-2 hover:bg-black/5">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn-ghost" rel="next">Berikutnya</a>
        @else
            <span class="btn-ghost opacity-40">Berikutnya</span>
        @endif
    </div>
</nav>
@endif
