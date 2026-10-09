@php
    // Notes written by the system itself are shown in the page's language; an admin's own note is shown as typed.
    $noteOf = function (?string $note): ?string {
        if ($note === null || $note === '') {
            return null;
        }
        if (preg_match('/\ARestored version (\d+)\z/', $note, $m) === 1) {
            return __('Restored version :version', ['version' => $m[1]]);
        }

        return $note === 'Back to the Qistas look' ? __('Back to the Qistas look') : $note;
    };
@endphp
<section id="history" class="studio-panel studio-history-panel" aria-labelledby="history-title">
    <header class="studio-panel-head">
        <h2 id="history-title">{{ __('History') }}</h2>
        <p>{{ __('Every published look is kept. Restoring one publishes it again as a new version, so nothing is ever lost.') }}</p>
    </header>

    @if ($history->isEmpty())
        <p class="studio-empty">{{ __('Nothing has been published yet. Everyone sees the Qistas look.') }}</p>
    @else
        <ol class="studio-history">
            @foreach ($history as $version)
                @php
                    $isLive = $loop->first;
                    $light = $version->tokens['light'] ?? [];
                    $note = $noteOf($version->note);
                @endphp
                <li class="version" @if ($isLive) aria-current="true" @endif>
                    <span class="version-swatches" aria-hidden="true">
                        @foreach (['primary', 'accent', 'info', 'bg'] as $token)
                            @if (isset($light[$token]))<x-swatch :hex="$light[$token]" :size="16" />@endif
                        @endforeach
                    </span>
                    <div class="version-body">
                        <p class="version-title">
                            <span>{{ __('Version :version', ['version' => $version->version]) }}</span>
                            @if ($isLive)<span class="badge badge-ok">{{ __('Live') }}</span>@endif
                        </p>
                        <p class="version-meta">
                            <time datetime="{{ $version->published_at?->toIso8601String() }}">{{ $version->published_at?->translatedFormat('j M Y, H:i') }}</time>
                            · {{ $version->publishedBy?->name ?? __('Someone who has left') }}
                        </p>
                        @if ($note)<p class="version-note">{{ $note }}</p>@endif
                    </div>
                    @if ($canChange && ! $isLive)
                        <form method="post" action="{{ route('admin.appearance.restore', ['version' => $version->version]) }}" data-restore="{{ $version->version }}">
                            @csrf
                            <button type="submit" class="btn btn-quiet btn-sm"><x-icon name="undo" :size="16" /> {{ __('Restore') }}</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($canChange && ($live->hasCustomColours() || $live->images() !== []))
        <details class="studio-reset">
            <summary>{{ __('Go back to the Qistas look') }}</summary>
            <div class="studio-reset-body">
                <p>{{ __('Publishes the Qistas colours with no pictures of your own, as a new version. The welcome banners stay as they are.') }}</p>
                <form method="post" action="{{ route('admin.appearance.reset') }}">
                    @csrf
                    <button type="submit" class="btn btn-quiet btn-sm">{{ __('Publish the Qistas look') }}</button>
                </form>
            </div>
        </details>
    @endif
</section>
