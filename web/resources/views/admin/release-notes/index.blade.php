{{-- What's new (Win Plan PP17): the notes the app shows after each update, per version and language. --}}
@php
    $names = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'es' => 'Español', 'ur' => 'اردو'];
    $rtl = ['ar', 'ur'];
@endphp
<x-layouts.admin :title="__('What’s new')" section="release-notes">
    <x-page-head :title="__('What’s new')">
        <x-slot:subtitle>{{ __('The app shows these notes once after it updates, in the reader’s language, or in English where there is no translation.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card card-pad stack" aria-labelledby="note-title">
            <h2 id="note-title" class="card-title">{{ $editingVersion ? __('Notes for :version', ['version' => $editingVersion]) : __('Notes for a version') }}</h2>
            <form method="POST" action="{{ route('admin.release-notes.store') }}" class="form stack" novalidate>
                @csrf
                <x-field name="version" :label="__('App version')" :value="$editingVersion" dir="ltr" autocomplete="off" placeholder="1.16.0" :hint="__('Saving a version again replaces its notes.')" />
                @foreach ($languages as $language)
                    <x-field type="textarea" :name="'body['.$language.']'" :id="'body-'.$language" :label="$names[$language] ?? $language" rows="3" maxlength="3000"
                        :value="$editing[$language] ?? ''" :dir="in_array($language, $rtl, true) ? 'rtl' : 'ltr'" :lang="$language" />
                    @error('body.'.$language)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @endforeach
                <label class="check">
                    <input type="hidden" name="publish" value="0">
                    <input type="checkbox" name="publish" value="1" @checked(old('publish', $editingPublished))>
                    <span>{{ __('Published: the app shows it') }}</span>
                </label>
                <div><button class="btn btn-gold" type="submit">{{ __('Save the notes') }}</button></div>
            </form>
        </section>

        <section class="card" aria-labelledby="versions-title">
            <div class="card-head"><h2 id="versions-title">{{ __('Versions') }}</h2></div>
            @if ($versions->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon name="megaphone" :size="24" /></span>
                    <h3>{{ __('No notes yet') }}</h3>
                    <p>{{ __('Write what changed in the next version; the app shows it once after the update.') }}</p>
                </div>
            @else
                <ul class="rows">
                    @foreach ($versions as $version => $info)
                        <li>
                            <a class="row-main" href="{{ route('admin.release-notes.index', ['version' => $version]) }}">
                                <span class="row-title" dir="ltr">{{ $version }}</span>
                                <span class="row-sub">{{ collect($info['languages'])->map(fn ($l) => $names[$l] ?? $l)->join(', ') }}</span>
                            </a>
                            <span class="badge {{ $info['published'] ? 'badge-ok' : '' }}">{{ $info['published'] ? __('Published') : __('Draft') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.admin>
