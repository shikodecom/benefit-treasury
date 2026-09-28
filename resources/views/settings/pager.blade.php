@if ($paginator->hasPages())
<nav class="pagination row" aria-label="ページ切り替え">
    @if ($paginator->onFirstPage())
        <span class="muted">前へ</span>
    @else
        <a class="button secondary" href="{{ $paginator->previousPageUrl() }}">前へ</a>
    @endif
    <span>{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} ページ</span>
    @if ($paginator->hasMorePages())
        <a class="button secondary" href="{{ $paginator->nextPageUrl() }}">次へ</a>
    @else
        <span class="muted">次へ</span>
    @endif
</nav>
@endif
