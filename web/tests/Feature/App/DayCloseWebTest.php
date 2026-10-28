<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Domain\DayClose\SummaryText;
use App\Entitlements\Feature;
use App\Models\DayClose;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Dates;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Daily-habit wave A, H4 on the web workspace (ruling C7): the page at /app/day-close. It carries what the person typed
| to the same actions the API calls (CloseDay, ReopenDay) and shows what they answer; the rules are theirs, the words of
| the sentence are SummaryText's. So these tests are about what the page shows and who may use it: the figures, the
| refusals next to the field they belong to, the seal of a closed day, the Reopen form by role, and "off" meaning off.
|
| Al-Fares is in Riyadh (UTC+3), so noon UTC on 21 October 2026 is 15:00 there, the same day. Ahmad Ali's contract has room
| for every payment taken, and he keeps a phone, an e-mail and a national ID so a test can prove none of them is shown.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->shop->update(['name' => 'Al-Fares']);
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->mona = closeStaff($this->shop, 'Mona Manager', 'manager');
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->omar = closeStaff($this->shop, 'Omar Collector');
    $this->vera = closeStaff($this->shop, 'Vera Viewer', 'viewer');
    $this->contract = closeContract($this->shop, overrides: ['principal' => '20000.00']);
    $this->travelTo('2026-10-21 12:00:00');
});

/** The page, as [$user] sees it. */
function dwWeb(object $test, User $user, string $query = ''): TestResponse
{
    return $test->actingAs($user)->get(route('app.day-close.show').$query);
}

/** [$user] closes the day from the page: what the form sends. */
function dwSubmit(object $test, User $user, array $fields = []): TestResponse
{
    return $test->actingAs($user)->from(route('app.day-close.show'))->post(route('app.day-close.store'), $fields + ['counted_cash' => '100.00']);
}

/** The one close of the day [$date], as the database holds it. */
function dwStored(Tenant $tenant, string $date = '2026-10-21'): DayClose
{
    return asTenant($tenant, fn () => DayClose::query()->where('day', $date)->orderByDesc('version')->firstOrFail());
}

function dwCount(Tenant $tenant): int
{
    return asTenant($tenant, fn () => DayClose::query()->count());
}

/** A day with cash, a card payment with a note and a void, as the brief's page shows it: 100.00 in cash to count. */
function dwBusyDay(object $test): void
{
    closeTake($test->contract, '100.00', by: $test->sara);
    app(RecordPayment::class)->handle($test->contract, '50.00', 'card', by: $test->omar, note: 'Ref 7731');
    app(VoidTransaction::class)->handle(closeTake($test->contract, '30.00', by: $test->omar), 'Counted twice', $test->boss);
}

/** Another workspace with its own owner. */
function dwStrangers(): array
{
    $other = closeShop();

    return [closeStaff($other, 'Stranger Owner', 'owner'), $other];
}

/** What [$words] says in the language [$language], the way the application switches to it for a request. */
function dwIn(string $language, Closure $words): mixed
{
    $before = app()->getLocale();
    app()->setLocale($language);
    try {
        return $words();
    } finally {
        app()->setLocale($before);
    }
}

/** The sentence a close is shared as, in the language [$language] (the one the application is in by default). */
function dwSentence(DayClose $close, Tenant $tenant, ?string $language = null): string
{
    return $language === null ? SummaryText::for($close, $tenant) : dwIn($language, fn () => SummaryText::for($close, $tenant));
}

/** The attribute of the form that closes the day: its address is the page's own, so only the form says it is there. */
function dwForm(): string
{
    return 'method="POST" action="'.route('app.day-close.store').'"';
}

/** The attribute of the form that reopens [$close]. */
function dwReopenForm(DayClose $close): string
{
    return 'action="'.route('app.day-close.reopen', $close).'"';
}

it('sends guests to sign in', function () {
    $this->get(route('app.day-close.show'))->assertRedirect(route('login'));
});

describe('today', function () {
    it('shows today\'s expected cash, the other payments and what to look at', function () {
        dwBusyDay($this);

        $page = dwWeb($this, $this->boss)->assertOk()
            ->assertSee('Day close')
            ->assertSee(Dates::day(CarbonImmutable::parse('2026-10-21'), 'l, j F Y'))
            // Cash: taken 100.00 and 30.00, less the 30.00 voided, so 100.00 is what should be in the till.
            ->assertSeeInOrder([__('Expected cash'), Format::money('100.00', 'SAR')])
            ->assertSeeInOrder([__('Other payments'), 'Ahmad Ali', __('Card'), 'Ref 7731', Format::money('50.00', 'SAR')])
            ->assertSeeInOrder([__('Worth a look'), 'Ahmad Ali', __('Payment voided'), Format::money('30.00', 'SAR')])
            ->assertSee('name="counted_cash"', false)
            ->assertSee(dwForm(), false)
            ->assertSee(__('Close the day'));

        // The page is for the day's money: never a customer's phone, e-mail or national ID.
        $page->assertDontSee('1098765432')->assertDontSee('+966501234567')->assertDontSee('ahmad@example.test');
    });

    it('says so, in sentences, when nothing has been collected', function () {
        dwWeb($this, $this->boss)->assertOk()
            ->assertSee(__('Nothing collected yet today.'))
            ->assertSee(__('No other payments recorded for this day.'))
            ->assertSee(__('Nothing out of the ordinary on this day.'));
    });

    it('shows a payment voided today as its own line among the other payments', function () {
        app(VoidTransaction::class)->handle(closeTake($this->contract, '40.00', 'card', by: $this->omar), 'Wrong card', $this->boss);

        dwWeb($this, $this->boss)->assertOk()->assertSeeInOrder([__('Other payments'), 'Ahmad Ali', __('Payment voided'), Format::money('-40.00', 'SAR'), __('Worth a look')]);
    });

    it('escapes what customers and notes are called', function () {
        $odd = customerIn($this->shop, ['name' => '<b>Zed</b> & "Co"']);
        $contract = openContract($this->shop, ['customer_id' => $odd->id, 'principal' => '5000.00', 'installment_count' => 10]);
        app(RecordPayment::class)->handle($contract, '70.00', 'card', by: $this->omar, note: '<script>alert(1)</script>');

        dwWeb($this, $this->boss)->assertOk()
            ->assertSee('&lt;b&gt;Zed&lt;/b&gt; &amp; &quot;Co&quot;', false)->assertDontSee('<b>Zed</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    });

    it('hands the live difference the cash the server expects, as text, with the words to say it', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        // The page hands the script the cash the day expects (a string, never a number) and the words to use.
        $config = dwWeb($this, $this->boss)->assertOk()->assertSee('data-config', false)->getContent();
        preg_match('/data-config="([^"]+)"/', (string) $config, $found);
        $data = json_decode(html_entity_decode($found[1]), true);

        expect($data['expected'])->toBe('100.00')->and($data['currency'])->toBe('SAR')
            ->and($data['messages'])->toHaveKeys(['balanced', 'short', 'over']);
    });
});

describe('closing the day', function () {
    it('closes the day with a counted amount and then shows it closed', function () {
        dwBusyDay($this);

        dwSubmit($this, $this->sara, ['counted_cash' => '95.00', 'note' => 'Short at the till'])
            ->assertRedirect(route('app.day-close.show'))->assertSessionHas('status', __('The day is closed.'))->assertSessionHasNoErrors();

        $close = dwStored($this->shop);
        expect($close->status)->toBe('closed')->and($close->counted_cash)->toBe('95.0000')->and($close->expected_cash)->toBe('100.0000')
            ->and($close->difference)->toBe('-5.0000')->and($close->closed_by_user_id)->toBe($this->sara->id);

        $page = dwWeb($this, $this->sara)->assertOk()
            ->assertSee('data-seal', false)->assertSee(__('Closed'))
            // The sentence is SummaryText's, word for word, with a button to copy it and the signed PDF beside it.
            ->assertSee(dwSentence($close, $this->shop))
            ->assertSee(__('Copy the sentence'))->assertSee(__('Copied'))
            ->assertSee(route('app.day-close.summary', $close), false)
            ->assertSeeInOrder([__('Expected cash'), Format::money('100.00', 'SAR'), __('Counted cash'), Format::money('95.00', 'SAR')])
            ->assertSee(__('Short by :amount', ['amount' => Format::money('5.00', 'SAR')]))
            // Nothing left to close: the form is gone.
            ->assertDontSee(dwForm(), false)->assertDontSee('name="counted_cash"', false);
        expect($page->getContent())->toContain('Short at the till');
    });

    it('shows a day that balanced in plain words', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwSubmit($this, $this->sara, ['counted_cash' => '100.00']);

        dwWeb($this, $this->sara)->assertOk()->assertSee(__('Balanced'))->assertSee(dwSentence(dwStored($this->shop), $this->shop));
    });

    it('shows a day that was over in plain words', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwSubmit($this, $this->sara, ['counted_cash' => '112.50']);

        dwWeb($this, $this->sara)->assertOk()->assertSee(__('Over by :amount', ['amount' => Format::money('12.50', 'SAR')]));
    });

    it('lets a collector close the day, and shows no Reopen form', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwSubmit($this, $this->omar)->assertSessionHasNoErrors();
        $close = dwStored($this->shop);

        dwWeb($this, $this->omar)->assertOk()->assertSee('data-seal', false)
            ->assertDontSee(dwReopenForm($close), false)->assertDontSee(__('Reopen this day'));
    });

    test('a second submit does not close it twice', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwSubmit($this, $this->sara)->assertSessionHasNoErrors();
        dwSubmit($this, $this->omar)->assertRedirect(route('app.day-close.show'))
            ->assertSessionHasErrors(['day' => __('This day is already closed.')]);

        expect(dwCount($this->shop))->toBe(1)->and(dwStored($this->shop)->closed_by_user_id)->toBe($this->sara->id);
    });

    it('answers a repeat of the very same press with the first answer', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $once = (string) Str::uuid();

        $first = dwSubmit($this, $this->sara, ['_once' => $once])->assertRedirect(route('app.day-close.show'));
        $repeat = dwSubmit($this, $this->sara, ['_once' => $once])->assertRedirect(route('app.day-close.show'))->assertSessionHasNoErrors();

        expect(dwCount($this->shop))->toBe(1)->and($repeat->headers->get('Location'))->toBe($first->headers->get('Location'));
    });

    it('reads an amount typed on an Arabic keyboard', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwSubmit($this, $this->sara, ['counted_cash' => '٩٥٫٥٠'])->assertSessionHasNoErrors();

        expect(dwStored($this->shop)->counted_cash)->toBe('95.5000');
    });

    it('shows a mistake next to the counted-amount field, and keeps what was typed', function () {
        dwSubmit($this, $this->sara, ['counted_cash' => 'abc'])->assertRedirect(route('app.day-close.show'))
            ->assertSessionHasErrors(['counted_cash' => __('Enter a valid amount.')]);

        $this->actingAs($this->sara)->followingRedirects()->from(route('app.day-close.show'))
            ->post(route('app.day-close.store'), ['counted_cash' => '10.005'])
            ->assertOk()
            ->assertSee(__('The amount can have at most two decimals.'))
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="10.005"', false);
        expect(dwCount($this->shop))->toBe(0);
    });

    it('asks for the counted amount when it is left empty', function () {
        dwSubmit($this, $this->sara, ['counted_cash' => ''])->assertSessionHasErrors('counted_cash');

        expect(dwCount($this->shop))->toBe(0);
    });

    it('tells a collector why the second close was refused, on the page that now shows the day closed', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        dwSubmit($this, $this->sara)->assertSessionHasNoErrors();

        // The other phone's page was still open: its close is refused, and the page it lands on is the closed day.
        $this->actingAs($this->omar)->followingRedirects()->from(route('app.day-close.show'))
            ->post(route('app.day-close.store'), ['counted_cash' => '100.00'])
            ->assertOk()->assertSee('data-seal', false)->assertSee(__('This day is already closed.'));
    });

    it('shows a refusal about the day where the person sees it', function () {
        // A day still to come cannot be closed: the action says so, on `day`, and the page puts it above the button.
        $this->actingAs($this->sara)->followingRedirects()->from(route('app.day-close.show'))
            ->post(route('app.day-close.store'), ['counted_cash' => '10.00', 'day' => '2026-10-22'])
            ->assertOk()->assertSee(__('A day that has not come yet cannot be closed.'));
        expect(dwCount($this->shop))->toBe(0);
    });
});

describe('who may', function () {
    test('a viewer is refused', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        dwWeb($this, $this->vera)->assertForbidden();
        $this->actingAs($this->vera)->post(route('app.day-close.store'), ['counted_cash' => '100.00'])->assertForbidden();
        expect(dwCount($this->shop))->toBe(0);
    });

    it('refuses a viewer the reopening of a close', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');

        $this->actingAs($this->vera)->post(route('app.day-close.reopen', $close), ['reason' => 'Wanted to look'])->assertForbidden();
        expect(dwStored($this->shop)->status)->toBe('closed');
    });

    it('shows the Reopen form to an owner and to a manager, and no one else', function (string $who, bool $sees) {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $page = dwWeb($this, $this->{$who})->assertOk();

        $sees
            ? $page->assertSee(dwReopenForm($close), false)->assertSee(__('Reopen this day'))
            : $page->assertDontSee(dwReopenForm($close), false)->assertDontSee(__('Reopen this day'));
    })->with([
        'owner' => ['boss', true],
        'manager' => ['mona', true],
        'collector' => ['sara', false],
    ]);

    it('shows the navigation entry to those who may close, while the feature is on', function (string $who, bool $sees) {
        $page = $this->actingAs($this->{$who})->get(route('app.payments.index'))->assertOk();

        $sees
            ? $page->assertSee('href="'.route('app.day-close.show').'"', false)->assertSee(__('Day close'))
            : $page->assertDontSee(route('app.day-close.show'), false);
    })->with([
        'owner' => ['boss', true],
        'manager' => ['mona', true],
        'collector' => ['sara', true],
        'viewer' => ['vera', false],
    ]);

    test('the navigation entry appears only when the feature is on', function () {
        $this->actingAs($this->boss)->get(route('app.payments.index'))->assertOk()->assertSee('href="'.route('app.day-close.show').'"', false);

        switchOff(Feature::DayClose);

        $this->actingAs($this->boss)->get(route('app.payments.index'))->assertOk()->assertDontSee(route('app.day-close.show'), false);
    });
});

describe('reopening', function () {
    test('an owner can reopen with a reason', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '90.00');

        $this->actingAs($this->boss)->from(route('app.day-close.show'))
            ->post(route('app.day-close.reopen', $close), ['reason' => 'A payment was missed'])
            ->assertRedirect(route('app.day-close.show'))->assertSessionHas('status')->assertSessionHasNoErrors();

        $reopened = asTenant($this->shop, fn () => DayClose::findOrFail($close->id));
        expect($reopened->status)->toBe('reopened')->and($reopened->reopen_reason)->toBe('A payment was missed')
            ->and($reopened->reopened_by_user_id)->toBe($this->boss->id);

        // The day is open again: the form is back, and the page says why it is.
        dwWeb($this, $this->boss)->assertOk()
            ->assertSee(dwForm(), false)->assertDontSee('data-seal', false)
            ->assertSee(__('This day was reopened. Close it again when the figures are right.'))->assertSee('A payment was missed');
    });

    it('lets a manager reopen too, and the day can then be closed again as the next version', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $this->actingAs($this->mona)->post(route('app.day-close.reopen', $close), ['reason' => 'Counted wrongly'])->assertRedirect();

        dwSubmit($this, $this->sara, ['counted_cash' => '0.00'])->assertSessionHasNoErrors();

        expect(dwCount($this->shop))->toBe(2)->and(dwStored($this->shop)->version)->toBe(2)->and(dwStored($this->shop)->status)->toBe('closed');
    });

    it('refuses a collector', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');

        $this->actingAs($this->sara)->post(route('app.day-close.reopen', $close), ['reason' => 'I would like to'])->assertForbidden();
        expect(dwStored($this->shop)->status)->toBe('closed');
    });

    it('shows the action\'s own words beside the reason when it is too short, and keeps the form open', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');

        $this->actingAs($this->boss)->from(route('app.day-close.show'))
            ->post(route('app.day-close.reopen', $close), ['reason' => 'no'])
            ->assertRedirect(route('app.day-close.show'))->assertSessionHasErrors(['reason' => __('Give a reason of at least :min characters.', ['min' => 5])]);

        $page = $this->actingAs($this->boss)->followingRedirects()->from(route('app.day-close.show'))
            ->post(route('app.day-close.reopen', $close), ['reason' => 'no'])
            ->assertOk()->assertSee(__('Give a reason of at least :min characters.', ['min' => 5]))
            ->assertSee('aria-invalid="true"', false);

        // The form stays open for the person to fix it: no hunting for where the mistake was.
        expect(preg_match('/<details[^>]*data-reopen[^>]*\sopen[\s>]/', (string) $page->getContent()))->toBe(1)
            ->and(dwStored($this->shop)->status)->toBe('closed');
    });

    it('tells the hint of how short a reason may be', function () {
        closeDay($this->shop, '2026-10-21', $this->sara, '0.00');

        dwWeb($this, $this->boss)->assertOk()->assertSee(__('At least :min characters.', ['min' => 5]));
    });
});

describe('the history', function () {
    it('shows a closed day\'s history, the newest day first, with its status, difference and PDF', function () {
        closeTake($this->contract, '100.00', by: $this->sara, at: '2026-10-20 09:00:00');
        $earlier = closeDay($this->shop, '2026-10-20', $this->sara, '95.00');
        closeTake($this->contract, '60.00', by: $this->sara);
        $today = closeDay($this->shop, '2026-10-21', $this->sara, '60.00');
        reopenDay($today, 'Missed a payment', $this->boss);

        $page = dwWeb($this, $this->boss)->assertOk()
            ->assertSee(__('History'))
            ->assertSee(route('app.day-close.summary', $earlier), false)->assertSee(route('app.day-close.summary', $today), false)
            ->assertSee(__('Short by :amount', ['amount' => Format::money('5.00', 'SAR')]))
            ->assertSee(__('Reopened'));

        // Newest day first: the 21st (reopened) before the 20th.
        $html = (string) $page->getContent();
        expect(strpos($html, 'data-history="'.$today->id.'"'))->toBeLessThan(strpos($html, 'data-history="'.$earlier->id.'"'));
    });

    it('says so when there is nothing yet', function () {
        dwWeb($this, $this->boss)->assertOk()->assertSee(__('Closed days will be listed here.'));
    });

    it('pages the history', function () {
        // A day is closed once until it is reopened, so sixteen closes take a few days and a reopening between each.
        foreach (range(1, 16) as $lap) {
            $date = CarbonImmutable::parse('2026-10-21')->subDays($lap % 8)->format('Y-m-d');
            closeDay($this->shop, $date, $this->sara, (string) $lap);
            reopenDay(dwStored($this->shop, $date), 'Another go', $this->boss);
        }

        expect(dwCount($this->shop))->toBe(16)
            ->and(substr_count((string) dwWeb($this, $this->boss)->assertOk()->getContent(), 'data-history='))->toBe(15)
            ->and(substr_count((string) dwWeb($this, $this->boss, '?page=2')->assertOk()->getContent(), 'data-history='))->toBe(1);
    });
});

describe('an earlier day', function () {
    it('shows the figures of an earlier open day and closes that day', function () {
        closeTake($this->contract, '75.00', by: $this->sara, at: '2026-10-20 09:00:00');

        dwWeb($this, $this->boss, '?day=2026-10-20')->assertOk()
            ->assertSee(Dates::day(CarbonImmutable::parse('2026-10-20'), 'l, j F Y'))
            ->assertSee(Format::money('75.00', 'SAR'))
            ->assertSee('name="day" value="2026-10-20"', false);

        $this->actingAs($this->sara)->from(route('app.day-close.show', ['day' => '2026-10-20']))
            ->post(route('app.day-close.store'), ['counted_cash' => '75.00', 'day' => '2026-10-20'])
            ->assertRedirect(route('app.day-close.show', ['day' => '2026-10-20']))->assertSessionHasNoErrors();

        expect(dwStored($this->shop, '2026-10-20')->status)->toBe('closed');
        // Today stays open.
        expect(asTenant($this->shop, fn () => DayClose::query()->where('day', '2026-10-21')->count()))->toBe(0);
    });

    it('closes the day the person saw, even when midnight has passed since the page was opened', function () {
        closeTake($this->contract, '75.00', by: $this->sara);
        $seen = dwWeb($this, $this->sara)->assertOk();
        expect($seen->getContent())->toContain('name="day" value="2026-10-21"');

        $this->travelTo('2026-10-21 21:30:00'); // 00:30 on the 22nd in Riyadh
        dwSubmit($this, $this->sara, ['counted_cash' => '75.00', 'day' => '2026-10-21'])->assertSessionHasNoErrors();

        expect(dwStored($this->shop, '2026-10-21')->status)->toBe('closed');
    });

    it('goes back to today for a day that is not a day it can show', function (string $day) {
        dwWeb($this, $this->boss, '?day='.$day)->assertRedirect(route('app.day-close.show'));
    })->with([
        'a day to come' => ['2026-10-22'],
        'too long ago' => ['2026-09-01'],
        'not a date' => ['garbage'],
        'a day that is not on the calendar' => ['2026-02-31'],
    ]);
});

describe('the PDF', function () {
    it('opens the signed summary in the browser', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');

        $response = $this->actingAs($this->boss)->get(route('app.day-close.summary', $close))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        expect($response->headers->get('Content-Disposition'))->toStartWith('inline;')->and(substr((string) $response->getContent(), 0, 4))->toBe('%PDF');
    });
});

describe('Arabic', function () {
    it('renders the page right to left, with the Arabic sentence', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '95.00');

        $page = $this->actingAs($this->boss)->get(route('app.day-close.show', ['lang' => 'ar']))->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false)
            ->assertSee(dwSentence($close, $this->shop, 'ar'))
            ->assertSee(dwIn('ar', fn () => __('History')));

        expect($page->getContent())->not->toContain(dwSentence($close, $this->shop, 'en'));
    });

    it('reads the open page in Arabic too', function () {
        closeTake($this->contract, '100.00', by: $this->sara);

        $this->actingAs($this->boss)->get(route('app.day-close.show', ['lang' => 'ar']))->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee(dwIn('ar', fn () => __('Close the day')))
            ->assertSee(dwIn('ar', fn () => __('Other payments')));
    });
});

describe('off', function () {
    it('is not reachable when the feature is off', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
        switchOff(Feature::DayClose);

        // A page of its own is the plain "not available" page; a form goes back with the message. Nothing is written.
        $this->actingAs($this->boss)->get(route('app.day-close.show'))->assertForbidden()->assertSee('That is not available right now.');
        $this->actingAs($this->boss)->get(route('app.day-close.summary', $close))->assertForbidden();
        $this->actingAs($this->boss)->post(route('app.day-close.reopen', $close), ['reason' => 'Still wanted to'])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->boss)->post(route('app.day-close.store'), ['counted_cash' => '1.00', 'day' => '2026-10-20'])->assertRedirect()->assertSessionHas('error');

        expect(dwCount($this->shop))->toBe(1)->and(dwStored($this->shop)->status)->toBe('closed');
    });

    it('comes back with everything that was kept when it is switched on again', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
        switchOff(Feature::DayClose);
        switchOn(Feature::DayClose);

        dwWeb($this, $this->boss)->assertOk()->assertSee(dwSentence($close, $this->shop));
    });
});

describe('the workspace\'s own', function () {
    test('the page is the workspace\'s own', function () {
        [$strangerOwner, $other] = dwStrangers();
        $contract = closeContract($other, 'Zed Outsider');
        closeTake($contract, '777.00', 'card');
        $theirs = closeDay($other, '2026-10-21', $strangerOwner, '777.00');
        closeTake($this->contract, '10.00', by: $this->sara);

        // Their figures and their closes are not on this page; their close is not found here, whatever is done to it.
        dwWeb($this, $this->boss)->assertOk()->assertDontSee('Zed Outsider')->assertDontSee(Format::money('777.00', 'SAR'))
            ->assertDontSee(route('app.day-close.summary', $theirs), false)->assertDontSee('data-seal', false);
        $this->actingAs($this->boss)->get(route('app.day-close.summary', $theirs))->assertNotFound();
        $this->actingAs($this->boss)->post(route('app.day-close.reopen', $theirs), ['reason' => 'Not my shop'])->assertNotFound();

        expect(asTenant($other, fn () => DayClose::find($theirs->id)->status))->toBe('closed');
        // ...and what is closed there does not close the day here.
        dwWeb($this, $this->boss)->assertOk()->assertSee(dwForm(), false);
    });
});

describe('the dashboard', function () {
    it('offers to close the day only when it is due', function () {
        $link = 'href="'.route('app.day-close.show').'"';
        $card = __('Count the till and sign off today. It takes about 30 seconds.');

        // On, may close, not closed yet: the card.
        $this->actingAs($this->sara)->get(route('app.dashboard'))->assertOk()->assertSee($card)->assertSee($link, false);

        // Closed: nothing to offer.
        closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $this->actingAs($this->sara)->get(route('app.dashboard'))->assertOk()->assertDontSee($card);
    });

    it('does not offer it to a viewer, or when the feature is off', function () {
        $card = __('Count the till and sign off today. It takes about 30 seconds.');

        $this->actingAs($this->vera)->get(route('app.dashboard'))->assertOk()->assertDontSee($card);

        switchOff(Feature::DayClose);
        $this->actingAs($this->sara)->get(route('app.dashboard'))->assertOk()->assertDontSee($card)->assertDontSee(route('app.day-close.show'), false);
    });

    it('offers it again once a closed day is reopened', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        reopenDay($close, 'Missed something', $this->boss);

        $this->actingAs($this->sara)->get(route('app.dashboard'))->assertOk()->assertSee(__('Count the till and sign off today. It takes about 30 seconds.'));
    });
});
