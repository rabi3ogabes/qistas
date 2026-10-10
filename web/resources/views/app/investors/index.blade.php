@php
    use App\Domain\Investors\MainInvestor;
    use App\Models\Investor;
    use App\Support\Format;
    use App\Support\Money;

    $full = ! $entitlement->unlimited() && $entitlement->remaining() === 0;
    $onlyMain = $investors->count() === 1;
@endphp
<x-layouts.app :title="__('Investors')" section="investors">
    <x-page-head :title="__('Investors')" :subtitle="__('Who funds your contracts, and what each one has earned as customers pay.')">
        @can('create', Investor::class)
            @if ($full)
                <a class="btn btn-gold" href="{{ url('/app/billing') }}">{{ __('Upgrade to add partners') }}</a>
            @else
                <a class="btn" href="{{ route('app.investors.create') }}"><x-icon name="plus" :size="18" /> {{ __('Add an investor') }}</a>
            @endif
        @endcan
    </x-page-head>

    <div class="investor-grid">
        @foreach ($investors as ['model' => $investor, 'summary' => $summary])
            <a class="card investor-card" href="{{ route('app.investors.show', $investor) }}" @if ($investor->isArchived()) data-archived="true" @endif>
                <div class="investor-card-head">
                    <span class="investor-mark" @if ($investor->is_main) data-main="true" @endif aria-hidden="true">{{ mb_strtoupper(mb_substr(MainInvestor::displayName($investor), 0, 1)) }}</span>
                    <span class="investor-name">
                        <strong>{{ MainInvestor::displayName($investor) }}</strong>
                        <span class="row-sub">
                            @if ($investor->is_main)
                                {{ __('The business’s own money') }}
                            @elseif (Money::isPositive($investor->commission_percent))
                                {{ __('Partner, :percent% commission', ['percent' => rtrim(rtrim($investor->commission_percent, '0'), '.')]) }}
                            @else
                                {{ __('Partner') }}
                            @endif
                        </span>
                    </span>
                    @if ($investor->isArchived())<span class="badge">{{ __('Archived') }}</span>@endif
                </div>

                <div>
                    <p class="figure-label">{{ __('In the wallet') }}</p>
                    <p class="investor-wallet money" @if (Money::isNegative($summary['wallet'])) data-tone="negative" @endif>{{ Format::money($summary['wallet'], $currency) }}</p>
                    @if ($investor->is_main && Money::isNegative($summary['wallet']))
                        <p class="row-sub">{{ __('Record the money the business put in to see what is left.') }}</p>
                    @endif
                </div>

                <dl class="investor-facts">
                    <div><dt>{{ __('Out in contracts') }}</dt><dd class="money">{{ Format::money($summary['out_in_contracts'], $currency) }}</dd></div>
                    <div><dt>{{ __('Profit earned') }}</dt><dd class="money">{{ Format::money($summary['profit_earned'], $currency) }}</dd></div>
                    <div><dt>{{ __('Profit still to come') }}</dt><dd class="money">{{ Format::money($summary['profit_expected'], $currency) }}</dd></div>
                    <div><dt>{{ __('Contracts') }}</dt><dd class="money">{{ $summary['contracts'] }}</dd></div>
                    <div><dt>{{ __('Customers') }}</dt><dd class="money">{{ $summary['customers'] }}</dd></div>
                </dl>
            </a>
        @endforeach

        @if ($onlyMain)
            @can('create', Investor::class)
                <section class="card card-pad investor-invite">
                    <span class="empty-icon"><x-icon name="users" :size="22" /></span>
                    <h2 class="card-title">{{ __('Working with partners?') }}</h2>
                    <p class="muted">{{ __('Add each partner with the money they put in. Mark which contracts they fund, and their profit grows as customers pay.') }}</p>
                    @if ($full)
                        <a class="btn btn-gold btn-sm" href="{{ url('/app/billing') }}">{{ __('See plans and upgrade') }}</a>
                    @else
                        <a class="btn btn-quiet btn-sm" href="{{ route('app.investors.create') }}">{{ __('Add an investor') }}</a>
                    @endif
                </section>
            @endcan
        @endif
    </div>
</x-layouts.app>
