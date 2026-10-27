<?php

use App\Billing\Card\CardSignatureInvalid;
use App\Billing\Card\FakeStripeGateway;

/*
| Reading a webhook message (spec 2026-10-27 §9): Stripe's own library checks the signature on the raw body with this
| site's secret and allows five minutes; anything else is refused. The live gateway and the fake share this code.
*/

it('reads a message Stripe signed with this site\'s secret', function () {
    $signed = FakeStripeGateway::signedEvent('customer.subscription.updated', ['id' => 'sub_1', 'status' => 'active'], 'whsec_site', 'evt_1');

    $event = (new FakeStripeGateway)->event($signed['payload'], $signed['header'], 'whsec_site');

    expect($event->id)->toBe('evt_1')
        ->and($event->type)->toBe('customer.subscription.updated')
        ->and($event->object)->toBe(['id' => 'sub_1', 'status' => 'active'])
        ->and($event->livemode)->toBeFalse();
});

it('refuses a message signed with another secret', function () {
    $signed = FakeStripeGateway::signedEvent('invoice.paid', ['id' => 'in_1'], 'whsec_someone_else');

    expect(fn () => (new FakeStripeGateway)->event($signed['payload'], $signed['header'], 'whsec_site'))->toThrow(CardSignatureInvalid::class);
});

it('refuses a message whose body was changed after signing', function () {
    $signed = FakeStripeGateway::signedEvent('invoice.paid', ['id' => 'in_1', 'amount_paid' => 1200], 'whsec_site');
    $changed = str_replace('1200', '1', $signed['payload']);

    expect(fn () => (new FakeStripeGateway)->event($changed, $signed['header'], 'whsec_site'))->toThrow(CardSignatureInvalid::class);
});

it('refuses a message signed more than five minutes ago', function () {
    $signed = FakeStripeGateway::signedEvent('invoice.paid', ['id' => 'in_1'], 'whsec_site', signedAt: time() - 301);

    expect(fn () => (new FakeStripeGateway)->event($signed['payload'], $signed['header'], 'whsec_site'))->toThrow(CardSignatureInvalid::class);
});

it('refuses a missing or broken signature header and a body that is not JSON', function (string $payload, string $header) {
    expect(fn () => (new FakeStripeGateway)->event($payload, $header, 'whsec_site'))->toThrow(CardSignatureInvalid::class);
})->with([
    'no header' => ['{"id":"evt_1"}', ''],
    'nonsense header' => ['{"id":"evt_1"}', 'hello'],
    'not JSON' => ['not json', 't=1,v1=abc'],
]);
