<?php

use App\Actions\RecordPayment;
use App\Actions\VoidTransaction;
use App\Documents\BusinessProfile;
use App\Entitlements\Entitlements;
use App\Entitlements\Feature;
use App\Models\DayClose;
use App\Models\Document;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Settings\TenantSettings;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Daily-habit wave A, H4: the API under the end-of-day close (Tasks 7 and 8 hold the rules; this holds the routes). The
| screens in the app and on the web call exactly these, and show the `summary_text` sentence as it is sent here.
|
| The workspace is Al-Fares in Riyadh (UTC+3), so noon UTC on 21 October 2026 is 15:00 there, the same day. Ahmad Ali's
| contract has room for every payment these tests take, and he keeps a phone, an e-mail and a national ID so a test can
| prove none of them reaches an answer or the PDF.
*/

beforeEach(function () {
    $this->travelTo('2026-10-01 06:00:00');
    switchOn(Feature::DayClose);
    $this->shop = closeShop();
    $this->shop->update(['name' => 'Al-Fares']);
    $this->sara = closeStaff($this->shop, 'Sara Collector');
    $this->omar = closeStaff($this->shop, 'Omar Collector');
    $this->boss = closeStaff($this->shop, 'Boss Owner', 'owner');
    $this->mona = closeStaff($this->shop, 'Mona Manager', 'manager');
    $this->contract = closeContract($this->shop, overrides: ['principal' => '20000.00']);
    $this->travelTo('2026-10-21 12:00:00');
});

/** Signs [$user] in to the API from now on in the test. */
function dcSignIn(User $user): User
{
    Sanctum::actingAs($user, ['app']);

    return $user;
}

/**
 * The day's takings of the example in the brief: 14 payments, 8,420.00 in all. Sara takes 3,100.00 in cash (three
 * payments) and Omar 5,320.00 by card (eleven), so the cash to count is 3,100.00.
 */
function dcBusyDay(object $test): void
{
    foreach (['1000.00', '1000.00', '1100.00'] as $amount) {
        closeTake($test->contract, $amount, by: $test->sara);
    }
    foreach (array_fill(0, 10, '480.00') as $amount) {
        closeTake($test->contract, $amount, 'card', by: $test->omar);
    }
    closeTake($test->contract, '520.00', 'card', by: $test->omar);
}

/** Pages' worth of the day-close history, as the person at [$user] sees it. */
function dcHistory(object $test, string $query = ''): array
{
    return $test->getJson('/api/v1/day-close'.$query)->assertOk()->json();
}

/** A day with cash, a card payment with a note and a void, closed by Sara after a count that is 5.00 short. */
function dcClosedDay(object $test): DayClose
{
    closeTake($test->contract, '100.00', by: $test->sara);
    app(RecordPayment::class)->handle($test->contract, '50.00', 'card', by: $test->omar, note: 'Ref 7731');
    app(VoidTransaction::class)->handle(closeTake($test->contract, '30.00', by: $test->omar), 'Counted twice', $test->boss);

    return closeDay($test->shop, '2026-10-21', $test->sara, '95.00', 'Short at the till');
}

function dcPdf(object $test, DayClose $close, string $query = ''): string
{
    $response = $test->get("/api/v1/day-close/{$close->id}/summary.pdf".$query)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    return (string) $response->getContent();
}

function dcUsed(Tenant $tenant): int
{
    return (int) Entitlements::for($tenant)->check(Feature::PdfStatements)->used();
}

describe('today', function () {
    test('today shows expected cash, the other payments and what to look at', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $card = app(RecordPayment::class)->handle($this->contract, '50.00', 'card', by: $this->omar, note: 'Ref 7731');
        $mistake = closeTake($this->contract, '30.00', by: $this->omar);
        $void = app(VoidTransaction::class)->handle($mistake, 'Counted twice', $this->boss);

        dcSignIn($this->sara);
        $response = $this->getJson('/api/v1/day-close/today')->assertOk();
        $data = $response->json('data');

        expect($data['day'])->toBe('2026-10-21')
            ->and($data['closing_time'])->toBe('20:00')
            ->and($data['closed'])->toBeFalse()
            ->and($data['close'])->toBeNull()
            ->and($data['expected_cash'])->toBe('100.00')
            ->and($data['by_method'])->toBe(['cash' => '100.00', 'card' => '50.00'])
            ->and($data['count'])->toBe(2)
            ->and($data['per_member'])->toBe([
                $this->boss->id => ['name' => 'Boss Owner', 'cash' => '-30.00', 'count' => 0],
                $this->omar->id => ['name' => 'Omar Collector', 'cash' => '30.00', 'count' => 0],
                $this->sara->id => ['name' => 'Sara Collector', 'cash' => '100.00', 'count' => 1],
            ])
            ->and($data['non_cash'])->toBe([['id' => $card->id, 'customer' => 'Ahmad Ali', 'amount' => '50.00', 'method' => 'card', 'note' => 'Ref 7731']])
            ->and($data['watch'])->toBe([['kind' => 'void', 'id' => $void->id, 'label' => 'Ahmad Ali', 'amount' => '30.00']])
            ->and($data['can_close'])->toBeTrue()
            ->and($data['can_reopen'])->toBeFalse();

        // Nothing about the customer beyond the name he is known by.
        expect($response->getContent())->not->toContain('1098765432')->not->toContain('966501234567')->not->toContain('ahmad@example.test');
    });

    it('is empty objects, not empty lists, on a day with no money', function () {
        dcSignIn($this->sara);

        $body = $this->getJson('/api/v1/day-close/today')->assertOk()->getContent();

        // An app reads these as maps: an empty one must still be `{}`.
        expect($body)->toContain('"by_method":{}')->toContain('"per_member":{}')->toContain('"non_cash":[]')->toContain('"watch":[]')
            ->and(json_decode($body, true)['data']['expected_cash'])->toBe('0.00');
    });

    it('takes the closing time from the workspace setting', function () {
        TenantSettings::for($this->shop)->set('day_close.closing_time', '21:30', $this->boss);

        dcSignIn($this->sara);
        $this->getJson('/api/v1/day-close/today')->assertOk()->assertJsonPath('data.closing_time', '21:30');
    });

    it('says who can close and who can reopen, and follows the day through a close and a reopening', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        $viewer = closeStaff($this->shop, 'Vera Viewer', 'viewer');

        // Before the close: a viewer reads and cannot close.
        dcSignIn($viewer);
        $this->getJson('/api/v1/day-close/today')->assertOk()->assertJsonPath('data.can_close', false)->assertJsonPath('data.can_reopen', false);

        $close = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');

        // Closed: the close is there, nobody can close again, an owner or manager can reopen and a collector cannot.
        foreach ([[$this->boss, true], [$this->mona, true], [$this->sara, false], [$viewer, false]] as [$who, $canReopen]) {
            dcSignIn($who);
            $this->getJson('/api/v1/day-close/today')->assertOk()
                ->assertJsonPath('data.closed', true)->assertJsonPath('data.close.id', $close->id)->assertJsonPath('data.close.status', 'closed')
                ->assertJsonPath('data.can_close', false)->assertJsonPath('data.can_reopen', $canReopen);
        }

        // Reopened: the day is open again, the reopened close is still the one shown, and a collector may close it again.
        reopenDay($close, 'A late payment came in', $this->boss);
        dcSignIn($this->sara);
        $this->getJson('/api/v1/day-close/today')->assertOk()
            ->assertJsonPath('data.closed', false)->assertJsonPath('data.close.id', $close->id)->assertJsonPath('data.close.status', 'reopened')
            ->assertJsonPath('data.can_close', true)->assertJsonPath('data.can_reopen', false);
    });
});

describe('closing the day', function () {
    test('a collector closes the day and gets a shareable sentence', function () {
        dcBusyDay($this);
        dcSignIn($this->sara);

        $response = $this->postJson('/api/v1/day-close', ['counted_cash' => '3100.00', 'note' => 'Counted twice'])->assertCreated();
        $data = $response->json('data');

        // The sentence the person shares: one line, in the request's language, the day's own date, nothing rounded.
        expect(str_replace("\u{00A0}", ' ', $data['summary_text']))->toBe('Al-Fares, 21 Oct: collected SAR 8,420 (cash 3,100, other 5,320), 14 payments, difference 0.');

        expect($data)->toMatchArray([
            'day' => '2026-10-21',
            'version' => 1,
            'status' => 'closed',
            'expected_cash' => '3100.00',
            'counted_cash' => '3100.00',
            'difference' => '0.00',
            'note' => 'Counted twice',
            'closed_by' => ['id' => $this->sara->id, 'name' => 'Sara Collector'],
            'closed_at' => '2026-10-21T12:00:00Z',
            'reopened_by' => null,
            'reopened_at' => null,
            'reopen_reason' => null,
            'summary_url' => url("/api/v1/day-close/{$data['id']}/summary.pdf"),
        ])->and($data['totals'])->toBe([
            'by_method' => ['cash' => '3100.00', 'card' => '5320.00'],
            'count' => 14,
            // The cash side of the day, by the person who took it: Omar took cards only.
            'per_member' => [$this->sara->id => ['name' => 'Sara Collector', 'cash' => '3100.00', 'count' => 3]],
        ])->and($data['snapshot_hash'])->toMatch('/^[0-9a-f]{64}$/');

        // What an app receives has no one's national ID, phone or e-mail.
        expect($response->getContent())->not->toContain('1098765432')->not->toContain('966501234567')->not->toContain('ahmad@example.test');

        $stored = asTenant($this->shop, fn () => DayClose::sole());
        expect($stored->id)->toBe($data['id'])->and($stored->closed_by_user_id)->toBe($this->sara->id);
    });

    it('closes a day short or over and says so', function () {
        closeTake($this->contract, '100.00', by: $this->sara);
        dcSignIn($this->sara);

        $short = $this->postJson('/api/v1/day-close', ['counted_cash' => '87.50'])->assertCreated()->json('data');
        $over = $this->postJson('/api/v1/day-close', ['counted_cash' => '112.50', 'day' => '2026-10-20'])->assertCreated()->json('data');

        expect($short['difference'])->toBe('-12.50')
            ->and(str_replace("\u{00A0}", ' ', $short['summary_text']))->toBe('Al-Fares, 21 Oct: collected SAR 100.00, 1 payment, short by SAR 12.50.')
            ->and($over['day'])->toBe('2026-10-20')
            ->and($over['difference'])->toBe('112.50')
            ->and(str_replace("\u{00A0}", ' ', $over['summary_text']))->toBe('Al-Fares, 20 Oct: no payments collected, over by SAR 112.50.');
    });

    it('closes the day it is told to, and today when it is not', function () {
        dcSignIn($this->sara);

        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => '2026-10-17'])->assertCreated()->assertJsonPath('data.day', '2026-10-17');
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'])->assertCreated()->assertJsonPath('data.day', '2026-10-21');

        // The workspace's own day, not the UTC one: at 00:30 in Riyadh the 22nd has begun.
        $this->travelTo('2026-10-21 21:30:00');
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'])->assertCreated()->assertJsonPath('data.day', '2026-10-22');
    });

    test('a viewer is refused', function () {
        $viewer = closeStaff($this->shop, 'Vera Viewer', 'viewer');
        dcSignIn($viewer);

        // Refused before anything else is looked at, so a viewer's mistakes are not explained either.
        $this->postJson('/api/v1/day-close', ['counted_cash' => '10.00'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        $this->postJson('/api/v1/day-close', [])->assertForbidden();

        expect(asTenant($this->shop, fn () => DayClose::count()))->toBe(0);
    });

    test('closing twice is a validation error, not a second row', function () {
        dcSignIn($this->sara);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'])->assertCreated();

        $this->postJson('/api/v1/day-close', ['counted_cash' => '5.00'])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.fields.day', [__('This day is already closed.')]);

        expect(asTenant($this->shop, fn () => DayClose::pluck('counted_cash')->all()))->toBe(['0.0000']);
    });

    it('answers a retry of the same close with the first answer', function () {
        dcSignIn($this->sara);
        $key = 'close-2026-10-21-by-sara';

        $first = $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'], ['Idempotency-Key' => $key])->assertCreated();
        $again = $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'], ['Idempotency-Key' => $key])->assertCreated();

        expect($again->json('data.id'))->toBe($first->json('data.id'))->and(asTenant($this->shop, fn () => DayClose::count()))->toBe(1);
    });

    it('leaves the day and the amount to the close itself, and passes the amount as it was typed', function () {
        dcSignIn($this->sara);

        // The request only says the day is a date and the cash is text; the rest is CloseDay's.
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => 'yesterday'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['day']]]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => '21/10/2026'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['day']]]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => '2026-02-30'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['day']]]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => '2026-10-22'])->assertUnprocessable()
            ->assertJsonPath('error.fields.day', [__('A day that has not come yet cannot be closed.')]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'day' => '2026-10-13'])->assertUnprocessable()
            ->assertJsonPath('error.fields.day', [__('Only today and the seven days before it can be closed.')]);

        $this->postJson('/api/v1/day-close', [])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['counted_cash']]]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => 115.5])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['counted_cash']]]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '-1'])->assertUnprocessable()
            ->assertJsonPath('error.fields.counted_cash', [__('The counted cash cannot be negative.')]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '1.234'])->assertUnprocessable()
            ->assertJsonPath('error.fields.counted_cash', [__('The amount can have at most two decimals.')]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '1e3'])->assertUnprocessable()
            ->assertJsonPath('error.fields.counted_cash', [__('Enter a valid amount.')]);
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00', 'note' => str_repeat('x', 1001)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['note']]]);

        expect(asTenant($this->shop, fn () => DayClose::count()))->toBe(0);

        // Typed as people type it: no trailing zeros, no decimals at all.
        $this->postJson('/api/v1/day-close', ['counted_cash' => '12.5', 'day' => '2026-10-20'])->assertCreated()->assertJsonPath('data.counted_cash', '12.50');
        $this->postJson('/api/v1/day-close', ['counted_cash' => '7', 'day' => '2026-10-19', 'note' => '   '])->assertCreated()
            ->assertJsonPath('data.counted_cash', '7.00')->assertJsonPath('data.note', null);
    });
});

describe('reopening', function () {
    test('only an owner or manager can reopen', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $accountant = closeStaff($this->shop, 'Adel Accountant', 'accountant');

        foreach ([$this->sara, $accountant] as $who) {
            dcSignIn($who);
            $this->postJson("/api/v1/day-close/{$close->id}/reopen", ['reason' => 'A late payment came in'])->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        }
        expect($close->fresh()->status)->toBe('closed');

        dcSignIn($this->boss);
        $this->postJson("/api/v1/day-close/{$close->id}/reopen", [])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->postJson("/api/v1/day-close/{$close->id}/reopen", ['reason' => 'late'])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason']]]);
        $this->postJson("/api/v1/day-close/{$close->id}/reopen", ['reason' => str_repeat('x', 501)])->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason']]]);

        $this->travelTo('2026-10-21 18:00:00');
        $data = $this->postJson("/api/v1/day-close/{$close->id}/reopen", ['reason' => 'A late payment came in'])->assertOk()->json('data');

        expect($data)->toMatchArray([
            'id' => $close->id,
            'status' => 'reopened',
            'reopened_by' => ['id' => $this->boss->id, 'name' => 'Boss Owner'],
            'reopened_at' => '2026-10-21T18:00:00Z',
            'reopen_reason' => 'A late payment came in',
        ]);

        // Once: a close that is reopened cannot be reopened again.
        $this->postJson("/api/v1/day-close/{$close->id}/reopen", ['reason' => 'Once more, please'])->assertUnprocessable()
            ->assertJsonPath('error.fields.day', [__('Only a day that is closed can be reopened.')]);

        // A manager may too; the day closes again as the next version.
        dcSignIn($this->sara);
        $second = $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'])->assertCreated()->assertJsonPath('data.version', 2)->json('data');
        dcSignIn($this->mona);
        $this->postJson("/api/v1/day-close/{$second['id']}/reopen", ['reason' => 'Counted wrong'])->assertOk()->assertJsonPath('data.reopened_by.name', 'Mona Manager');
    });

    it('is a refusal for a viewer before it is anything else', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        dcSignIn(closeStaff($this->shop, 'Vera Viewer', 'viewer'));

        $this->postJson("/api/v1/day-close/{$close->id}/reopen", [])->assertForbidden();
    });
});

describe('history', function () {
    test('history is newest first and paginated', function () {
        foreach (['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'] as $day) {
            $latest = closeDay($this->shop, $day, $this->sara, '0.00');
        }
        reopenDay($latest, 'A late payment came in', $this->boss);
        closeDay($this->shop, '2026-10-21', $this->omar, '0.00');
        dcSignIn($this->boss);

        $first = dcHistory($this, '?per_page=3');
        $second = dcHistory($this, '?per_page=3&page=2');
        $last = dcHistory($this, '?per_page=3&page=3');
        $label = fn (array $page): array => array_map(fn (array $row): string => $row['day'].' v'.$row['version'].' '.$row['status'], $page['data']);

        expect($label($first))->toBe(['2026-10-21 v2 closed', '2026-10-21 v1 reopened', '2026-10-20 v1 closed'])
            ->and($label($second))->toBe(['2026-10-19 v1 closed', '2026-10-18 v1 closed', '2026-10-17 v1 closed'])
            ->and($label($last))->toBe(['2026-10-16 v1 closed', '2026-10-15 v1 closed'])
            ->and($first['meta'])->toMatchArray(['current_page' => 1, 'last_page' => 3, 'per_page' => 3, 'total' => 8])
            ->and($first['links']['next'])->not->toBeNull()
            ->and($last['links']['next'])->toBeNull();

        // Each row is the whole close, sentence and PDF address included.
        expect($first['data'][0])->toHaveKeys(['id', 'day', 'version', 'status', 'expected_cash', 'counted_cash', 'difference', 'totals', 'note', 'closed_by', 'closed_at', 'summary_text', 'summary_url'])
            ->and($first['data'][0]['closed_by']['name'])->toBe('Omar Collector')
            ->and($first['data'][1]['reopened_by']['name'])->toBe('Boss Owner');

        // One page of twenty unless asked, and never more than a hundred.
        expect(dcHistory($this)['meta']['per_page'])->toBe(20)->and(dcHistory($this, '?per_page=500')['meta']['per_page'])->toBe(100);
    });

    it('reads a page of closes in the same number of queries however many there are', function () {
        dcSignIn($this->boss);
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/day-close')->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $one = $queries();
        foreach (['2026-10-15', '2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20'] as $day) {
            closeDay($this->shop, $day, $this->omar, '0.00');
        }

        expect($queries())->toBe($one);
    });

    it('is open to everyone in the team, a viewer included', function () {
        closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        dcSignIn(closeStaff($this->shop, 'Vera Viewer', 'viewer'));

        expect(dcHistory($this)['data'])->toHaveCount(1);
    });

    it('shows a close whose closer has left the team, and says no one closed it', function () {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        DB::table('users')->where('id', $this->sara->id)->delete();
        dcSignIn($this->boss);

        $row = dcHistory($this)['data'][0];
        $today = $this->getJson('/api/v1/day-close/today')->assertOk()->json('data.close');

        expect($row['id'])->toBe($close->id)->and($row['closed_by'])->toBeNull()->and($row['summary_text'])->toContain('Al-Fares')
            ->and($today['closed_by'])->toBeNull();
        // The PDF can still be made.
        $this->get("/api/v1/day-close/{$close->id}/summary.pdf")->assertOk();
    });
});

describe('when the feature is not enabled', function () {
    test('it answers 403 feature_unavailable when the platform switch is off', function (string $method, string $path) {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        switchOff(Feature::DayClose);
        dcSignIn($this->boss);

        $this->json($method, str_replace('{close}', $close->id, $path), ['counted_cash' => '0.00', 'reason' => 'A late payment came in'])
            ->assertForbidden()->assertJsonPath('error.code', 'feature_unavailable');

        // Off is off, and what exists stays.
        expect(asTenant($this->shop, fn () => DayClose::sole()->status))->toBe('closed');
    })->with([
        'today' => ['GET', '/api/v1/day-close/today'],
        'history' => ['GET', '/api/v1/day-close'],
        'close' => ['POST', '/api/v1/day-close'],
        'reopen' => ['POST', '/api/v1/day-close/{close}/reopen'],
        'summary' => ['GET', '/api/v1/day-close/{close}/summary.pdf'],
    ]);

    test('it answers 402 when the plan lacks it', function (string $method, string $path) {
        $close = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        Plan::where('key', 'free')->sole()->setFeature(Feature::DayClose, false);
        dcSignIn($this->boss);

        $this->json($method, str_replace('{close}', $close->id, $path), ['counted_cash' => '0.00', 'reason' => 'A late payment came in'])
            ->assertStatus(402)->assertJsonPath('error.code', 'feature_locked')->assertJsonPath('error.feature', 'day_close');
    })->with([
        'today' => ['GET', '/api/v1/day-close/today'],
        'history' => ['GET', '/api/v1/day-close'],
        'close' => ['POST', '/api/v1/day-close'],
        'reopen' => ['POST', '/api/v1/day-close/{close}/reopen'],
        'summary' => ['GET', '/api/v1/day-close/{close}/summary.pdf'],
    ]);

    it('needs a token', function () {
        $this->getJson('/api/v1/day-close/today')->assertUnauthorized();
        $this->postJson('/api/v1/day-close', ['counted_cash' => '0.00'])->assertUnauthorized();
    });
});

describe('the summary PDF', function () {
    test('the summary PDF opens, shows the day\'s figures, and counts one unit of the PDF allowance', function () {
        limitFreePlan(Feature::PdfStatements, 3);
        $close = dcClosedDay($this);
        dcSignIn($this->mona);

        $response = $this->get("/api/v1/day-close/{$close->id}/summary.pdf?language=en")->assertOk();
        $pdf = (string) $response->getContent();
        $text = pdfText($pdf);

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('day-summary-2026-10-21.pdf')
            ->and($response->headers->get('X-Document-Code'))->toMatch('/^[A-Z2-9]{10}$/')
            ->and(str_starts_with($pdf, '%PDF'))->toBeTrue()->and(pdfPages($pdf))->toBe(1);

        expect($text)->toContain('Day summary')->toContain('Al-Fares')->toContain('21 Oct 2026')
            // Who closed it, and the cash: 100.00 expected, 95.00 counted, 5.00 short.
            ->toContain('Sara Collector')->toContain('Expected cash')->toContain('100.00')->toContain('Counted cash')->toContain('95.00')
            ->toContain('Short by')->toContain('5.00')->toContain('Short at the till')
            // By way of paying, and by member.
            ->toContain('Cash')->toContain('Card')->toContain('50.00')->toContain('Omar Collector')->toContain('Boss Owner')->toContain('-30.00')
            // The card payment, who paid and what was written beside it.
            ->toContain('Ahmad Ali')->toContain('Ref 7731');

        // Never an identity.
        expect($text)->not->toContain('1098765432')->not->toContain('966501234567')->not->toContain('ahmad@example.test');

        expect(dcUsed($this->shop))->toBe(1)
            ->and(asTenant($this->shop, fn () => Document::sole()->only(['kind', 'reference', 'total'])))->toBe(['kind' => 'day_close', 'reference' => '2026-10-21', 'total' => '150.0000']);
    });

    test('asking for the same PDF again within ten minutes does not count twice', function () {
        limitFreePlan(Feature::PdfStatements, 3);
        $close = dcClosedDay($this);
        dcSignIn($this->boss);

        $first = $this->get("/api/v1/day-close/{$close->id}/summary.pdf")->assertOk();
        $this->travel(9)->minutes();
        $again = $this->get("/api/v1/day-close/{$close->id}/summary.pdf")->assertOk();

        expect(dcUsed($this->shop))->toBe(1)
            ->and($again->headers->get('X-Document-Code'))->toBe($first->headers->get('X-Document-Code'))
            ->and(asTenant($this->shop, fn () => Document::count()))->toBe(1);

        // After ten minutes it is a new document, and it counts again.
        $this->travel(2)->minutes();
        $this->get("/api/v1/day-close/{$close->id}/summary.pdf")->assertOk();
        expect(dcUsed($this->shop))->toBe(2)->and(asTenant($this->shop, fn () => Document::count()))->toBe(2);
    });

    it('is a different document for each close of the day', function () {
        limitFreePlan(Feature::PdfStatements, 3);
        $first = dcClosedDay($this);
        reopenDay($first, 'A late payment came in', $this->boss);
        $second = closeDay($this->shop, '2026-10-21', $this->sara, '100.00');
        dcSignIn($this->boss);

        $this->get("/api/v1/day-close/{$first->id}/summary.pdf")->assertOk();
        $this->get("/api/v1/day-close/{$second->id}/summary.pdf")->assertOk();

        expect(dcUsed($this->shop))->toBe(2);
    });

    it('stops at the plan\'s allowance like every other document', function () {
        limitFreePlan(Feature::PdfStatements, 1);
        $first = closeDay($this->shop, '2026-10-21', $this->sara, '0.00');
        $second = closeDay($this->shop, '2026-10-20', $this->sara, '0.00');
        dcSignIn($this->boss);

        $this->get("/api/v1/day-close/{$first->id}/summary.pdf")->assertOk();
        $this->get("/api/v1/day-close/{$second->id}/summary.pdf")->assertStatus(402)->assertJsonPath('error.code', 'limit_reached');
        // The one already made is free to make again.
        $this->get("/api/v1/day-close/{$first->id}/summary.pdf")->assertOk();
    });

    it('says what a reopened close is', function () {
        $close = dcClosedDay($this);
        reopenDay($close, 'A late payment came in', $this->boss);
        dcSignIn($this->boss);

        expect(pdfText(dcPdf($this, $close, '?language=en')))->toContain('Reopened');
    });

    it('says when the day has changed since it was signed', function () {
        $close = dcClosedDay($this);
        dcSignIn($this->boss);
        expect(pdfText(dcPdf($this, $close, '?language=en')))->not->toContain('after it was closed');

        // A card payment is recorded into the closed day afterwards: the list of other payments would not match the signed figures.
        closeTake($this->contract, '40.00', 'card', by: $this->boss, at: '2026-10-21 08:00:00');

        expect(pdfText(dcPdf($this, $close, '?language=en')))->toContain('after it was closed');
    });

    it('is written in Arabic when asked', function () {
        $close = dcClosedDay($this);
        dcSignIn($this->boss);

        $text = pdfText(dcPdf($this, $close, '?language=ar'));

        expect(containsArabic($text, 'ملخص اليوم'))->toBeTrue();
    });

    test('the verify page names it a day summary', function () {
        $close = dcClosedDay($this);
        dcSignIn($this->boss);
        $code = $this->get("/api/v1/day-close/{$close->id}/summary.pdf")->assertOk()->headers->get('X-Document-Code');

        $this->app['auth']->forgetGuards();
        $this->get("/verify/{$code}")->assertOk()
            ->assertSee('Day summary')->assertSee('Al-Fares')->assertSee('2026-10-21')->assertSee('150.00')
            ->assertDontSee('Sara Collector')->assertDontSee('Ahmad Ali');
    });
});

describe('another workspace', function () {
    test('one workspace never sees another\'s closes', function () {
        $other = closeShop();
        $otherBoss = closeStaff($other, 'Other Owner', 'owner');
        $theirs = closeDay($other, '2026-10-21', $otherBoss, '0.00', 'Theirs');
        $mine = closeDay($this->shop, '2026-10-20', $this->sara, '0.00');
        dcSignIn($this->boss);

        // Their close is not in my history, and the day is not closed for me because it is for them.
        expect(array_column(dcHistory($this)['data'], 'id'))->toBe([$mine->id]);
        $this->getJson('/api/v1/day-close/today')->assertOk()->assertJsonPath('data.closed', false)->assertJsonPath('data.close', null);

        // Not found, never forbidden: I cannot even tell it exists.
        $this->postJson("/api/v1/day-close/{$theirs->id}/reopen", ['reason' => 'A late payment came in'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
        $this->get("/api/v1/day-close/{$theirs->id}/summary.pdf")->assertNotFound();
        expect(asTenant($other, fn () => DayClose::sole()->status))->toBe('closed');

        // And closing my own day does not touch theirs.
        $this->postJson('/api/v1/day-close', ['counted_cash' => '1.00'])->assertCreated();
        expect(asTenant($other, fn () => DayClose::count()))->toBe(1);
    });

    it('is a close that does not exist: not found', function () {
        dcSignIn($this->boss);

        $this->postJson('/api/v1/day-close/'.fake()->uuid().'/reopen', ['reason' => 'A late payment came in'])->assertNotFound();
        $this->get('/api/v1/day-close/'.fake()->uuid().'/summary.pdf')->assertNotFound();
    });
});

describe('the sentence in other languages', function () {
    test('summary_text is in Arabic when the request asks for Arabic', function () {
        dcBusyDay($this);
        asTenant($this->shop, fn () => BusinessProfile::for($this->shop)->save(['name_ar' => 'الفارس', 'name_en' => 'Al-Fares Mobiles'], $this->boss));
        dcSignIn($this->sara);

        $arabic = $this->postJson('/api/v1/day-close', ['counted_cash' => '3100.00', 'note' => 'Counted twice'], ['Accept-Language' => 'ar'])->assertCreated();
        $text = $arabic->json('data.summary_text');

        // The shop under its Arabic name, the day in Arabic, the money in Arabic, a count that works for any number.
        expect($arabic->headers->get('Content-Language'))->toBe('ar')
            ->and($text)->toContain('الفارس')->toContain('21 أكتوبر')->toContain('3,100')->toContain('5,320')->toContain('8,420')->toContain('ر.س')->toContain('14')
            ->and($text)->not->toContain('collected')->not->toContain('payments')->not->toContain('Al-Fares');

        // The same close read again in English has the English name and words.
        $english = $this->getJson('/api/v1/day-close', ['Accept-Language' => 'en'])->assertOk()->json('data.0.summary_text');
        expect($english)->toContain('Al-Fares Mobiles, 21 Oct: collected')->and($english)->not->toContain('الفارس');
    });

    it('is in French, Spanish and Urdu without a word of English left', function (string $lang, string $month) {
        dcBusyDay($this);
        dcSignIn($this->sara);

        $text = $this->postJson('/api/v1/day-close', ['counted_cash' => '3100.00'], ['Accept-Language' => $lang])->assertCreated()->json('data.summary_text');

        expect($text)->toContain('Al-Fares')->toContain($month)->not->toContain('collected')->not->toContain('payments')->not->toContain('difference')->not->toContain('other');
    })->with([
        'French' => ['fr', 'oct.'],
        'Spanish' => ['es', 'oct'],
        'Urdu' => ['ur', 'اکتوبر'],
    ]);
});
