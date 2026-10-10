{{--
    A document's button (Win Plan PP8): one click opens it with the workspace's choices; the panel beside it changes them
    for this one document (paper, text size, sections, a one-page summary, a period, the language). Opens in a new tab.
--}}
@props(['action', 'label', 'sections' => ['overdue', 'schedule', 'signature'], 'period' => false, 'summary' => true, 'papers' => ['a4', 'a5'], 'id'])
@php
    use App\Documents\DocumentPreferences;
    use App\Tenancy\CurrentTenant;

    $tenant = app(CurrentTenant::class)->get();
    $preferences = $tenant ? DocumentPreferences::for($tenant) : null;
    $role = $tenant ? auth()->user()?->roleIn($tenant->id) : null;
    // The cost is the shop's: only offered to someone who sees its money.
    $sections = array_values(array_filter($sections, fn (string $s) => $s !== 'cost' || ($role?->seesInvestors() ?? false)));
    $sectionLabels = ['cost' => __('What it cost you'), 'overdue' => __('Overdue column'), 'schedule' => __('Schedule'), 'signature' => __('Signature')];
    $paperLabels = ['a4' => 'A4', 'a5' => 'A5', '80mm' => __('Till roll, 80 mm'), '58mm' => __('Till roll, 58 mm')];
    $languages = ['en' => 'English', 'ar' => 'العربية', 'fr' => 'Français', 'es' => 'Español', 'ur' => 'اردو'];
@endphp
<div class="doc-menu">
    <a class="btn btn-quiet" href="{{ $action }}" target="_blank" rel="noopener"><x-icon name="fileText" :size="18" /> {{ $label }}</a>
    <details class="doc-options">
        <summary class="btn btn-quiet btn-icon" aria-label="{{ __('Choices for this document') }}" title="{{ __('Choices for this document') }}"><x-icon name="sliders" :size="18" /></summary>
        <form class="doc-options-panel form" method="GET" action="{{ $action }}" target="_blank">
            <p class="doc-options-title">{{ __('Choices for this document') }}</p>
            <div class="field">
                <label for="{{ $id }}-paper">{{ __('Paper') }}</label>
                <select id="{{ $id }}-paper" name="paper">
                    @foreach ($papers as $paper)
                        <option value="{{ $paper }}" @selected($preferences?->paper === $paper)>{{ $paperLabels[$paper] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="{{ $id }}-text">{{ __('Text size') }}</label>
                <select id="{{ $id }}-text" name="text">
                    @foreach (['small' => __('Small'), 'normal' => __('Normal'), 'large' => __('Large')] as $size => $sizeLabel)
                        <option value="{{ $size }}" @selected(($preferences?->text ?? 'normal') === $size)>{{ $sizeLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="{{ $id }}-language">{{ __('Language') }}</label>
                <select id="{{ $id }}-language" name="language">
                    @foreach ($languages as $code => $name)
                        <option value="{{ $code }}" lang="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            @if ($period)
                <div class="doc-options-period">
                    <div class="field"><label for="{{ $id }}-from">{{ __('From') }}</label><input id="{{ $id }}-from" type="date" name="from" max="{{ today()->format('Y-m-d') }}"></div>
                    <div class="field"><label for="{{ $id }}-to">{{ __('To') }}</label><input id="{{ $id }}-to" type="date" name="to" max="{{ today()->format('Y-m-d') }}"></div>
                </div>
            @endif
            @foreach ($sections as $section)
                <input type="hidden" name="sections[{{ $section }}]" value="0">
                <label class="check"><input type="checkbox" name="sections[{{ $section }}]" value="1" @checked($preferences?->sections[$section] ?? false)> <span>{{ $sectionLabels[$section] }}</span></label>
            @endforeach
            @if ($summary)
                <label class="check"><input type="checkbox" name="summary" value="1"> <span>{{ __('One-page summary') }}</span></label>
            @endif
            <label class="check"><input type="checkbox" name="compact" value="1"> <span>{{ __('Smaller header') }}</span></label>
            <button class="btn btn-gold" type="submit">{{ __('Open the PDF') }}</button>
        </form>
    </details>
</div>
