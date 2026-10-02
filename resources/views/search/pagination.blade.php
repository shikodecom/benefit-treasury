@if ($paginator->hasPages())
<nav class="search-pagination" aria-label="検索結果のページ送り">
    <p class="help">全{{ $paginator->total() }}件中 {{ $paginator->firstItem() }}〜{{ $paginator->lastItem() }}件</p>
    <div class="row">
        @if ($paginator->onFirstPage())
            <span class="button secondary" aria-disabled="true">前へ</span>
        @else
            <a class="button secondary" href="{{ $paginator->previousPageUrl() }}" rel="prev">前へ</a>
        @endif
        @foreach ($elements as $element)
            @if (is_string($element))
                <span>{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page == $paginator->currentPage())
                        <span class="button" aria-current="page" aria-label="{{ $page }}ページ目">{{ $page }}</span>
                    @else
                        <a class="button secondary" href="{{ $url }}" aria-label="{{ $page }}ページ目">{{ $page }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        @if ($paginator->hasMorePages())
            <a class="button secondary" href="{{ $paginator->nextPageUrl() }}" rel="next">次へ</a>
        @else
            <span class="button secondary" aria-disabled="true">次へ</span>
        @endif
    </div>
</nav>
@endif
