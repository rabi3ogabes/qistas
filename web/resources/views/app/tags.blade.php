{{-- The business's tags (Win Plan PP12): what groups its customers and filters its lists. --}}
@php
    use App\Models\Tag;

    $colourNames = ['grey' => __('Grey'), 'gold' => __('Gold'), 'green' => __('Green'), 'blue' => __('Blue'), 'red' => __('Red'), 'purple' => __('Purple')];
@endphp
<x-layouts.app :title="__('Tags')" section="customers">
    <x-page-head :title="__('Tags')">
        <x-slot:subtitle><a class="link" href="{{ route('app.customers.index') }}">{{ __('All customers') }}</a></x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <section class="card" aria-labelledby="tags-title">
            <div class="card-head"><h2 id="tags-title">{{ __('Your tags') }}</h2></div>
            @if ($tags->isEmpty())
                <div class="empty">
                    <span class="empty-icon"><x-icon name="tag" :size="24" /></span>
                    <h3>{{ __('No tags yet') }}</h3>
                    <p>{{ __('Group customers by shop, by employer or however you work, then filter every list by it.') }}</p>
                </div>
            @else
                <ul class="tag-rows">
                    @foreach ($tags as $tag)
                        <li class="tag-row">
                            <span class="tag-chip" data-colour="{{ $tag->colour }}">{{ $tag->name }}</span>
                            <span class="cell-sub">{{ __('Customers: :count', ['count' => $tag->customers_count]) }}</span>
                            @if ($canEdit)
                                <details class="confirm confirm-inline tag-edit">
                                    <summary class="link">{{ __('Edit') }}</summary>
                                    <form method="POST" action="{{ route('app.tags.update', $tag) }}" class="form tag-form">
                                        @csrf @method('PUT')
                                        <x-field :id="'tag-name-'.$tag->id" name="name" :label="__('Name')" :value="$tag->name" maxlength="40" required :bag="'tag-'.$tag->id" />
                                        <div class="field">
                                            <label for="tag-colour-{{ $tag->id }}">{{ __('Colour') }}</label>
                                            <select id="tag-colour-{{ $tag->id }}" name="colour">
                                                @foreach (Tag::COLOURS as $colour)<option value="{{ $colour }}" @selected($tag->colour === $colour)>{{ $colourNames[$colour] }}</option>@endforeach
                                            </select>
                                        </div>
                                        <div class="form-actions"><button class="btn btn-sm" type="submit">{{ __('Save') }}</button></div>
                                    </form>
                                    <form method="POST" action="{{ route('app.tags.destroy', $tag) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-ghost btn-sm danger-text" type="submit">{{ __('Remove this tag') }}</button>
                                    </form>
                                </details>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($canEdit)
            <section class="card card-pad stack" aria-labelledby="new-tag-title">
                <h2 id="new-tag-title" class="card-title">{{ __('Add a tag') }}</h2>
                <form method="POST" action="{{ route('app.tags.store') }}" class="form stack">
                    @csrf
                    <x-field name="name" :label="__('Name')" maxlength="40" required :hint="__('For example: Shop 2, Government staff.')" />
                    <div class="field">
                        <label for="new-tag-colour">{{ __('Colour') }}</label>
                        <select id="new-tag-colour" name="colour">
                            @foreach (Tag::COLOURS as $colour)<option value="{{ $colour }}">{{ $colourNames[$colour] }}</option>@endforeach
                        </select>
                    </div>
                    <div><button class="btn btn-gold" type="submit"><x-icon name="plus" :size="18" /> {{ __('Add tag') }}</button></div>
                </form>
            </section>
        @endif
    </div>
</x-layouts.app>
