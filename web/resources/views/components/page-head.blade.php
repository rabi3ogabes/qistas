{{-- A page's title row: the title (and an optional subtitle) on one side, its actions on the other. --}}
@props(['title', 'subtitle' => null])
<header class="page-head">
    <div>
        <h1 class="display">{{ $title }}</h1>
        @if (isset($subtitle) && trim((string) $subtitle) !== '')<p class="page-sub">{{ $subtitle }}</p>@endif
    </div>
    @if ($slot->isNotEmpty())<div class="page-actions">{{ $slot }}</div>@endif
</header>
