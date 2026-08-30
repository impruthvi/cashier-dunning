<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Replay\Exceptions\NoSuchStep;
use Impruthvi\CashierDunning\Replay\Exceptions\UnmatchedRequest;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Replay\Placeholders;
use Impruthvi\CashierDunning\Simulation\SimulationEnvironment;
use Laravel\Cashier\Cashier;
use Stripe\Exception\CardException;

$start = CarbonImmutable::parse('2026-01-01T00:00:00Z');

/**
 * @param  list<array<string, mixed>>  $steps
 */
function fixtureWithSteps(array $steps): Fixture
{
    return Fixture::fromArray([
        'format_version' => 1,
        'provider' => 'stripe',
        'scenario' => 'test',
        'provenance' => ['synthetic' => true],
        'manifest' => ['required_events' => [], 'optional_events' => []],
        'steps' => $steps,
    ]);
}

/** @return array<string, mixed> */
function stepWith(string $label, array $exchanges): array
{
    return [
        'advance_to' => '0d',
        'label' => $label,
        'events' => [['id' => '{{evt_1}}', 'type' => 'customer.subscription.updated']],
        'api_responses' => $exchanges,
    ];
}

/** @return array<string, mixed> */
function exchange(string $method, string $path, array $body = [], int $status = 200, array $response = []): array
{
    return [
        'match' => ['method' => $method, 'path' => $path, 'body' => $body],
        'response' => ['status' => $status, 'body' => $response ?: ['id' => '{{sub_1}}', 'object' => 'subscription', 'status' => 'past_due']],
    ];
}

function clientFor(Fixture $fixture, CarbonImmutable $start): FixtureHttpClient
{
    return new FixtureHttpClient($fixture, new Placeholders($start));
}

it('serves a recorded response for the current step', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    [$body, $status] = $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);

    expect($status)->toBe(200)
        ->and(json_decode($body, true)['status'])->toBe('past_due')
        ->and(json_decode($body, true)['id'])->toBe('sub_replay1');
});

it('answers a real Cashier call from the fixture with no network', function () use ($start) {
    // End to end: the SDK's own transport is replaced, Cashier builds its
    // StripeClient as usual, and the response comes off disk.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    $subscription = (new SimulationEnvironment(config(), $client))->run(
        fn () => Cashier::stripe()->subscriptions->retrieve('sub_replay1')
    );

    expect($subscription->status)->toBe('past_due')
        ->and($client->calls())->toHaveCount(1);

    CashierDunning::flush();
});

it('refuses a call the fixture never recorded and says what the step can answer', function () use ($start) {
    // Never an empty response. A replay that invents `{}` reports success while
    // proving nothing, and the app quietly branches on data that never existed.
    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    $client->request('get', 'https://api.stripe.com/v1/invoices/in_replay1', [], [], false);
})->throws(UnmatchedRequest::class, 'GET /v1/subscriptions/sub_replay1');

it('says so plainly when a step recorded no calls at all', function () use ($start) {
    $client = clientFor(fixtureWithSteps([stepWith('trial', [])]), $start);

    $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
})->throws(UnmatchedRequest::class, 'recorded no API calls at all');

it('refuses to answer with a response recorded at a later step', function () use ($start) {
    // The tempting bug. A subscription that is `trialing` at step 0 is
    // `past_due` at step 1; serving step 1's response early asserts something
    // that was not true yet, and the run stays green.
    $client = clientFor(fixtureWithSteps([
        stepWith('trial starts', []),
        stepWith('payment fails', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    $client->atStep(0);
    $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
})->throws(UnmatchedRequest::class, 'step 1 (payment fails)');

it('refuses to answer with a response recorded at an earlier step', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('trial starts', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
        stepWith('payment fails', []),
    ]), $start);

    $client->atStep(1);
    $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
})->throws(UnmatchedRequest::class, 'earlier point');

it('matches on method', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    $client->request('post', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
})->throws(UnmatchedRequest::class);

it('ignores parameters the fixture does not care about', function () use ($start) {
    // The SDK adds parameters between versions without changing behaviour.
    // Matching on all of them would fail replays for reasons unrelated to the
    // application under test.
    $client = clientFor(fixtureWithSteps([
        stepWith('cancel', [exchange('POST', '/v1/subscriptions/{{sub_1}}', ['cancel_at_period_end' => 'true'])]),
    ]), $start);

    [, $status] = $client->request(
        'post',
        'https://api.stripe.com/v1/subscriptions/sub_replay1',
        [],
        ['cancel_at_period_end' => 'true', 'expand' => ['latest_invoice'], 'proration_behavior' => 'none'],
        false
    );

    expect($status)->toBe(200);
});

it('refuses when a parameter the fixture does care about differs', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('cancel', [exchange('POST', '/v1/subscriptions/{{sub_1}}', ['cancel_at_period_end' => 'true'])]),
    ]), $start);

    $client->request('post', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], ['cancel_at_period_end' => 'false'], false);
})->throws(UnmatchedRequest::class);

it('treats a numeric parameter and its string form as the same request', function () use ($start) {
    // Stripe encodes every parameter as a string on the wire, so a quantity of
    // 2 in a fixture and "2" from the SDK are the same call.
    $client = clientFor(fixtureWithSteps([
        stepWith('seats', [exchange('POST', '/v1/subscription_items/{{si_1}}', ['quantity' => 2])]),
    ]), $start);

    [, $status] = $client->request('post', 'https://api.stripe.com/v1/subscription_items/si_replay1', [], ['quantity' => '2'], false);

    expect($status)->toBe(200);
});

it('resolves placeholders inside the response body', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}', [], 200, [
            'id' => '{{sub_1}}',
            'customer' => '{{cus_1}}',
            'current_period_end' => '{{t+14d}}',
        ])]),
    ]), $start);

    [$body] = $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
    $decoded = json_decode($body, true);

    expect($decoded['customer'])->toBe('cus_replay1')
        ->and($decoded['current_period_end'])->toBe($start->addDays(14)->getTimestamp());
});

it('replays a recorded error response as a real Stripe exception', function () use ($start) {
    // Error paths are part of billing behaviour. A recorded 402 has to arrive at
    // the application as the exception it would really see.
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);

    $client = clientFor(fixtureWithSteps([
        stepWith('card declined', [exchange('POST', '/v1/subscriptions', [], 402, [
            'error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.'],
        ])]),
    ]), $start);

    expect(fn () => (new SimulationEnvironment(config(), $client))->run(
        fn () => Cashier::stripe()->subscriptions->create(['customer' => 'cus_replay1'])
    ))->toThrow(CardException::class, 'Your card was declined.');

    CashierDunning::flush();
});

it('refuses a file upload', function () use ($start) {
    $client = clientFor(fixtureWithSteps([stepWith('trial', [])]), $start);

    $client->request('post', 'https://files.stripe.com/v1/files', [], [], true);
})->throws(UnmatchedRequest::class, 'not uploads');

it('ignores the API base so Connect and Files endpoints still match', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('trial', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    [, $status] = $client->request('get', 'https://api.stripe.com:443/v1/subscriptions/sub_replay1?expand[]=customer', [], [], false);

    expect($status)->toBe(200);
});

it('records every call it was asked to serve, including the step', function () use ($start) {
    $client = clientFor(fixtureWithSteps([
        stepWith('one', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
        stepWith('two', [exchange('GET', '/v1/subscriptions/{{sub_1}}')]),
    ]), $start);

    $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);
    $client->atStep(1);
    $client->request('get', 'https://api.stripe.com/v1/subscriptions/sub_replay1', [], [], false);

    expect($client->calls())->toBe([
        ['method' => 'GET', 'path' => '/v1/subscriptions/sub_replay1', 'step' => 0],
        ['method' => 'GET', 'path' => '/v1/subscriptions/sub_replay1', 'step' => 1],
    ]);
});

it('refuses to be pointed at a step the fixture does not have', function () use ($start) {
    // Always a runner bug. Degrading into "no recorded response" would send
    // whoever hits it looking at the wrong file.
    clientFor(fixtureWithSteps([stepWith('only', [])]), $start)->atStep(3);
})->throws(NoSuchStep::class, 'fixture has 1 step');
