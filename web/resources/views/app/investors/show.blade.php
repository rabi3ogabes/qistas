@php
    use App\Domain\Investors\MainInvestor;
    use App\Entitlements\Entitlements;
    use App\Entitlements\Feature;
    use App\Models\InvestorEntry;
    use App\Support\Format;
    use App\Support\Money;
    use Illuminate\Support\Carbon;

    $name = MainInvestor::displayName($investor);
    $canEdit = auth()->user()->can('update', $investor) && Entitlements::for($investor->tenant)->check(Feature::Investors)->enabled();
    $canReverse = auth()->user()->can('reverse', $investor) && $canEdit;
    $commission = rtrim(rtrim($investor->commission_percent, '0'), '.');

    // What each line means to the investor, in words. A commission reads by its direction.
    $label = fn (InvestorEntry $entry): string => match ($entry->type) {
        'deposit' => __('Money put in'),
        'withdrawal' => __('Money taken out'),
        'funding_out' => __('Funded a contract'),
        'funding_back' => __('Back from a cancelled contract'),
        'principal_back' => __('Principal back from a payment'),
        'profit_share' => __('Profit from a payment'),
        'commission' => Money::isNegative($entry->reverses_entry_id === null ? $entry->amount : Money::sub('0', $entry->amount)) ? __('Commission to the business') : __('Commission from a partner'),
        default => $entry->type,
    };
    $statusLabel = ['active' => __('Active'), 'settled' => __('Settled'), 'cancelled' => __('Cancelled')];
    $statusTone = ['active' => 'badge-info', 'settled' => 'badge-ok', 'cancelled' => ''];
    $peak = collect($months)->map(fn (array $m) => abs((float) $m['amount']))->max() ?: 1;
@endphp
<x-layouts.app :title="$name" section="investors">
    <x-page-head :title="$name">
        <x-slot:subtitle>
            @if ($investor->isArchived())<span class="badge">{{ __('Archived') }}</span>@endif
            @if ($investor->is_main)
                {{ __('The business’s own money') }}
            @elseif (Money::isPositive($investor->commission_percent))
                {{ __('Partner, :percent% commission', ['percent' => $commission]) }}
            @else
                {{ __('Partner') }}
            @endif
            · <a class="link" href="{{ route('app.investors.index') }}">{{ __('All investors') }}</a>
        </x-slot:subtitle>
        <x-document-menu id="investor-report" :action="route('app.investors.report', $investor)" :label="__('Report')" :period="true" :sections="['overdue', 'signature']" />
    </x-page-head>

    <section class="card summary summary-quad" aria-label="{{ __('Where this investor stands') }}">
        <div class="figure figure-lead" @if (Money::isNegative($summary['wallet'])) data-tone="danger" @endif>
            <p class="figure-label">{{ __('In the wallet') }}</p>
            <p class="figure-value money">{{ Format::money($summary['wallet'], $currency) }}</p>
            <p class="figure-note">{{ $investor->is_main && Money::isNegative($summary['wallet']) ? __('Record the money the business put in to see what is left.') : __('Money in, less money out and money funding contracts') }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Out in contracts') }}</p>
            <p class="figure-value money">{{ Format::money($summary['out_in_contracts'], $currency) }}</p>
            <p class="figure-note">{{ __('Principal customers have not paid back yet') }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Profit earned') }}</p>
            <p class="figure-value money">{{ Format::money($summary['profit_earned'], $currency) }}</p>
            <p class="figure-note">{{ __('Counted as customers pay') }}</p>
        </div>
        <div class="figure">
            <p class="figure-label">{{ __('Profit still to come') }}</p>
            <p class="figure-value money">{{ Format::money($summary['profit_expected'], $currency) }}</p>
            <p class="figure-note">{{ __('On contracts still running') }}</p>
        </div>
    </section>

    <div class="detail-layout">
        <div class="stack">
            @if ($canEdit)
                <section class="card card-pad stack" aria-labelledby="money-title">
                    <h2 id="money-title" class="card-title">{{ __('Record money in or out') }}</h2>
                    <form method="POST" action="{{ route('app.investors.entries.store', $investor) }}" class="form" novalidate>
                        @csrf
                        @error('entry')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                        <fieldset class="field choice">
                            <legend class="field-label">{{ __('Which way?') }}</legend>
                            <label class="choice-item">
                                <input type="radio" name="type" value="deposit" @checked(old('type', 'deposit') === 'deposit')>
                                <span><strong>{{ __('Put in') }}</strong><small>{{ __('Adds to the wallet') }}</small></span>
                            </label>
                            <label class="choice-item">
                                <input type="radio" name="type" value="withdrawal" @checked(old('type') === 'withdrawal')>
                                <span><strong>{{ __('Taken out') }}</strong><small>{{ __('Paid out to them') }}</small></span>
                            </label>
                        </fieldset>
                        <x-field name="amount" :label="__('Amount')" inputmode="decimal" autocomplete="off" dir="ltr" required />
                        <x-field name="occurred_on" type="date" :label="__('Date (optional)')" :hint="__('Leave empty for today.')" />
                        <x-field name="note" :label="__('Note (optional)')" autocomplete="off" maxlength="500" />
                        <button class="btn">{{ __('Record') }}</button>
                    </form>
                </section>
            @endif

            <section class="card card-pad stack" aria-labelledby="chart-title">
                <h2 id="chart-title" class="card-title">{{ __('Profit, month by month') }}</h2>
                <ol class="profit-bars" aria-describedby="chart-title">
                    @foreach ($months as $month)
                        @php($value = (float) $month['amount'])
                        <li class="profit-bar" style="--h: {{ max(2, round(abs($value) / $peak * 100)) }}%" @if ($value < 0) data-negative="true" @endif>
                            <span class="profit-bar-value money">{{ Format::money($month['amount'], $currency, 0) }}</span>
                            <span class="profit-bar-fill" aria-hidden="true"></span>
                            <span class="profit-bar-month">{{ Carbon::createFromFormat('Y-m-d', $month['month'].'-01')->translatedFormat('M') }}</span>
                        </li>
                    @endforeach
                </ol>
            </section>

            @if ($canEdit)
                <details class="card card-pad confirm investor-edit">
                    <summary class="card-title">{{ __('Edit details') }}</summary>
                    <form method="POST" action="{{ route('app.investors.update', $investor) }}" class="form" novalidate>
                        @csrf
                        @method('PUT')
                        @error('investor')<p class="alert alert-error" role="alert">{{ $message }}</p>@enderror
                        <x-field name="name" :label="__('Name')" :value="$name" autocomplete="off" required maxlength="120" />
                        @unless ($investor->is_main)
                            <x-field name="commission_percent" :label="__('Commission (optional)')" :value="$commission" :hint="__('The share of their profit that goes to the business, as a percent.')" inputmode="decimal" autocomplete="off" dir="ltr" />
                        @endunless
                        <x-field name="commercial_registration" :label="__('Commercial registration (optional)')" :value="$investor->commercial_registration" autocomplete="off" dir="ltr" maxlength="60" />
                        <x-field type="textarea" name="notes" :label="__('Notes (optional)')" :value="$investor->notes" rows="2" />
                        @unless ($investor->is_main)
                            <label class="check"><input type="checkbox" name="archived" value="1" @checked($investor->isArchived())> <span>{{ __('Archived: funds no new contracts. Everything they funded stays theirs.') }}</span></label>
                        @endunless
                        <button class="btn btn-quiet">{{ __('Save') }}</button>
                    </form>
                </details>
            @endif
        </div>

        <div class="stack">
            <section class="card" aria-labelledby="contracts-title">
                <div class="card-head"><h2 id="contracts-title">{{ __('Contracts they fund') }}</h2><span class="row-sub">{{ __('Customers: :count', ['count' => $summary['customers']]) }}</span></div>
                @if ($contracts === [])
                    <div class="empty">
                        <span class="empty-icon"><x-icon name="fileText" :size="22" /></span>
                        <p>{{ $investor->is_main ? __('Every contract nobody else funds is funded here.') : __('Choose them under “Funded by” when you open a contract.') }}</p>
                    </div>
                @else
                    <ul class="rows">
                        @foreach ($contracts as $contract)
                            <li>
                                <a class="row-main" href="{{ route('app.contracts.show', $contract['id']) }}">
                                    <span class="row-title">{{ $contract['customer']['name'] ?? $contract['reference'] }}</span>
                                    <span class="row-sub">{{ $contract['reference'] }} <span class="badge {{ $statusTone[$contract['status']] ?? '' }}">{{ $statusLabel[$contract['status']] ?? $contract['status'] }}</span></span>
                                </a>
                                <span class="row-amount investor-collected">
                                    <span class="money">{{ Format::money($contract['collected'], $currency) }}</span>
                                    <span class="row-sub">{{ __('of :amount', ['amount' => Format::money(Money::add($contract['financed'], $contract['markup_amount'], 2), $currency)]) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="card" aria-labelledby="entries-title">
                <div class="card-head"><h2 id="entries-title">{{ __('Money in and out') }}</h2></div>
                <div class="table-wrap">
                    <table class="table table-stack">
                        <thead>
                            <tr>
                                <th scope="col">{{ __('Date') }}</th>
                                <th scope="col">{{ __('What') }}</th>
                                <th scope="col" class="num">{{ __('Amount') }}</th>
                                @if ($canReverse)<th scope="col"><span class="sr-only">{{ __('Actions') }}</span></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                @php($isReversed = $reversed->has($entry->id))
                                <tr @if ($isReversed) class="voided-row" @endif>
                                    <td data-label="{{ __('Date') }}" class="money">{{ $entry->occurred_on->translatedFormat('j M Y') }}</td>
                                    <td data-label="{{ __('What') }}">
                                        <span class="line-title">
                                            {{ $label($entry) }}
                                            @if ($entry->reverses_entry_id !== null)<span class="badge badge-warn">{{ __('Reversal') }}</span>@endif
                                            @if ($isReversed)<span class="badge">{{ __('Reversed') }}</span>@endif
                                        </span>
                                        @if ($entry->contract)<a class="cell-sub link" href="{{ route('app.contracts.show', $entry->contract) }}">{{ $entry->contract->reference() }}</a>@endif
                                        @if ($entry->note)<span class="cell-sub">{{ $entry->note }}</span>@endif
                                    </td>
                                    <td data-label="{{ __('Amount') }}" class="num money @if ($isReversed) voided @endif" data-sign="{{ Money::isNegative($entry->amount) ? 'out' : 'in' }}">{{ Money::isPositive($entry->amount) ? '+' : '' }}{{ Format::money($entry->amount, $currency) }}</td>
                                    @if ($canReverse)
                                        <td>
                                            @if (in_array($entry->type, InvestorEntry::MANUAL, true) && $entry->reverses_entry_id === null && ! $isReversed)
                                                <details class="confirm confirm-inline">
                                                    <summary class="link">{{ __('Reverse') }}</summary>
                                                    <form method="POST" action="{{ route('app.investor-entries.reverse', $entry) }}">
                                                        @csrf
                                                        <p class="field-hint">{{ __('Adds the same amount the other way. Nothing is deleted.') }}</p>
                                                        <button class="btn btn-danger btn-sm">{{ __('Reverse this entry') }}</button>
                                                    </form>
                                                </details>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr><td colspan="4" class="muted">{{ __('Nothing yet. Money put in, taken out and earned from contracts appears here.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
