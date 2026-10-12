{{-- Bringing a shop's books in (Win Plan K9): send a file, see every row before anything is saved. --}}
@php
    use App\Import\Fields;
    use App\Support\Dates;

    $language = app()->getLocale();
@endphp
<x-layouts.app :title="__('Bring your customers in')" section="customers">
    <x-page-head :title="__('Bring your customers in')">
        <x-slot:subtitle>{{ __('From an Excel or CSV file. You see every row first, and nothing is saved until you confirm.') }}</x-slot:subtitle>
    </x-page-head>

    <div class="detail-layout">
        <div class="stack">
            <section class="card card-pad stack" aria-labelledby="import-file-title">
                <h2 id="import-file-title">{{ __('Your file') }}</h2>
                @if (! $entitlement->enabled())
                    <p class="muted">{{ __('Importing is not available on this workspace yet.') }}</p>
                @else
                    <form method="POST" action="{{ route('app.import.store') }}" enctype="multipart/form-data" class="form">
                        @csrf
                        <div class="field">
                            @php $reads = $ai['available'] && $ai['included']; @endphp
                            <label for="import-file">{{ $reads ? __('Excel or CSV file, a notebook photo or a PDF report') : __('Excel or CSV file') }}</label>
                            <input id="import-file" type="file" name="file" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet{{ $reads ? ',image/jpeg,image/png,application/pdf' : '' }}" required>
                            @error('file')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @if ($entitlement->limit() !== null)
                                <p class="field-hint">{{ __('Up to :count rows at a time.', ['count' => $entitlement->limit()]) }}</p>
                            @endif
                        </div>
                        @if ($ai['available'])
                            <div class="import-ai">
                                <x-icon name="sparkles" :size="18" />
                                <p>
                                    @if ($ai['included'])
                                        {{ __('A photo of a notebook page, or the PDF reports from your old app, are read for you into the same rows. You check every row before anything is saved.') }}
                                        @if ($ai['pages_left'] !== null)<br><span class="muted">{{ __('Pages left this month: :count', ['count' => $ai['pages_left']]) }}</span>@endif
                                    @else
                                        {{ __('Part of Pro: a photo of a notebook page, or the PDF reports from your old app, read for you into the same rows.') }}
                                        <a class="link" href="{{ route('pricing') }}">{{ __('See Pro') }}</a>
                                    @endif
                                </p>
                            </div>
                        @endif
                        <div class="form-actions">
                            <button class="btn btn-gold" type="submit"><x-icon name="upload" :size="18" /> {{ __('Read the file') }}</button>
                            <a class="btn btn-ghost" href="{{ route('app.import.template') }}"><x-icon name="download" :size="18" /> {{ __('Download a template') }}</a>
                        </div>
                    </form>
                @endif
            </section>

            @if ($recent->isNotEmpty())
                <section class="card" aria-labelledby="import-recent-title">
                    <div class="card-head"><h2 id="import-recent-title">{{ __('Your imports') }}</h2></div>
                    <ul class="rows" role="list">
                        @foreach ($recent as $import)
                            <li>
                                <a class="link" href="{{ route('app.import.show', $import) }}">{{ $import->filename }}</a>
                                <span class="row-sub">
                                    {{ Dates::day($import->created_at) }} ·
                                    {{ $import->status === 'done' ? __('Brought in') : __('Trial run, not confirmed') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <section class="card card-pad stack" aria-labelledby="import-columns-title">
            <h2 id="import-columns-title">{{ __('What a file can have') }}</h2>
            <p class="muted">{{ __('One customer per row, with a header row naming the columns. Columns are recognised in Arabic, English, French, Spanish and Urdu, and you can choose any column yourself.') }}</p>
            <dl class="import-fields">
                @foreach ($fields as $field)
                    <div>
                        <dt>{{ Fields::label($field) }}</dt>
                        <dd>{{ implode(' · ', array_slice(Fields::NAMES[$field][$language] ?? Fields::NAMES[$field]['en'], 0, 3)) }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="field-hint">{{ __('A balance with a number of instalments and a first due date becomes a contract with its plan, and what was paid becomes one payment dated today. A balance alone becomes an open account.') }}</p>
        </section>
    </div>
</x-layouts.app>
