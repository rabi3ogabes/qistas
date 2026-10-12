@php use App\Site\SitePages; @endphp
{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $page)
    @foreach ([null, ...$locales] as $code)
    <url>
        <loc>{{ $code === null ? SitePages::plain($page) : SitePages::url($page, [], $code) }}</loc>
        @foreach ($locales as $other)
        <xhtml:link rel="alternate" hreflang="{{ $other }}" href="{{ SitePages::url($page, [], $other) }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ SitePages::plain($page) }}"/>
    </url>
    @endforeach
@endforeach
</urlset>
