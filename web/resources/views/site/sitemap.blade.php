{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($pages as $path)
    @php($base = $path === '/' ? url('/').'/' : url($path))
    <url>
        <loc>{{ url($path) }}</loc>
        @foreach ($locales as $code)
        <xhtml:link rel="alternate" hreflang="{{ $code }}" href="{{ $base }}?lang={{ $code }}"/>
        @endforeach
        <xhtml:link rel="alternate" hreflang="x-default" href="{{ url($path) }}"/>
    </url>
@endforeach
</urlset>
