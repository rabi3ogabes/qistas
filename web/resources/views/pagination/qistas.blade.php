@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="{{ __('Page navigation') }}">
        @if ($paginator->onFirstPage())
            <span class="btn btn-quiet btn-sm" aria-disabled="true">{{ __('Previous') }}</span>
        @else
            <a class="btn btn-quiet btn-sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('Previous') }}</a>
        @endif

        <span class="pager-status money">{{ __('Page :page of :pages', ['page' => $paginator->currentPage(), 'pages' => $paginator->lastPage()]) }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn btn-quiet btn-sm" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('Next') }}</a>
        @else
            <span class="btn btn-quiet btn-sm" aria-disabled="true">{{ __('Next') }}</span>
        @endif
    </nav>
@endif
