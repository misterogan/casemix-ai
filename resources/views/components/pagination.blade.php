@if ($paginator->hasPages())
<nav class="flex items-center justify-between gap-3 border-t border-gray-100 px-5 py-4 dark:border-gray-800 text-sm" aria-label="Halaman">
    <p class="text-tinta-3">
        Menampilkan <span class="num font-medium text-tinta">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
        dari <span class="num font-medium text-tinta">{{ $paginator->total() }}</span>
    </p>
    <div class="flex items-center gap-1">
        @if ($paginator->onFirstPage())
            <span class="btn-secondary btn-sm opacity-40">Sebelumnya</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="btn-secondary btn-sm" rel="prev">Sebelumnya</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="px-2 text-tinta-3">…</span>
            @endif
            @if (is_array($element))
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="num grid size-10 place-items-center rounded-lg bg-brand-500 font-medium text-white" aria-current="page">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="num grid size-10 place-items-center rounded-lg font-medium text-gray-700 hover:bg-brand-50 hover:text-brand-500 dark:text-gray-400">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="btn-secondary btn-sm" rel="next">Berikutnya</a>
        @else
            <span class="btn-secondary btn-sm opacity-40">Berikutnya</span>
        @endif
    </div>
</nav>
@endif
