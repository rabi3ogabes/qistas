{{-- Wraps a legal document. When it is not available in the reader's language the English text is shown, marked as English. --}}
<x-layouts.site :title="$title" :path="$path">
    <div class="container prose-page">
        <article class="prose" @if ($fallback) lang="en" dir="ltr" @endif>
            @if ($fallback)
                <p class="alert alert-info" lang="{{ app()->getLocale() }}" dir="{{ \App\Support\Locale::direction() }}">{{ __('This document is currently available in English only.') }}</p>
            @endif
            @include($document)
        </article>
    </div>
</x-layouts.site>
