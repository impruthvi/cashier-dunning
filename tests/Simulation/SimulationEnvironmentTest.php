<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\Manifest;
use Impruthvi\CashierDunning\Fixtures\Provider;
use Impruthvi\CashierDunning\Fixtures\Step;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Simulation\SimulationContext;
use Impruthvi\CashierDunning\Simulation\SimulationEnvironment;
use Laravel\Cashier\Cashier;
use Stripe\ApiRequestor;

beforeEach(function () {
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);
});

afterEach(function () {
    CashierDunning::flush();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function environment(): SimulationEnvironment
{
    return app(SimulationEnvironment::class);
}

/** @param list<array<string, mixed>> $steps */
function simulationFixture(array $steps = []): Fixture
{
    return new Fixture(
        provider: Provider::Stripe,
        scenario: 'simulation-environment',
        provenance: ['synthetic' => true],
        manifest: new Manifest,
        steps: array_map(Step::fromArray(...), $steps, array_keys($steps)),
    );
}

it('resolves the simulation environment as a singleton', function () {
    expect(app(SimulationEnvironment::class))->toBe(app(SimulationEnvironment::class));
});

it('enters fixture steps with the matching clock, recordings and placeholders', function () {
    $fixture = simulationFixture(array_map(fn (string $status, string $offset): array => [
        'advance_to' => $offset,
        'label' => $status,
        'events' => [],
        'api_responses' => [[
            'match' => ['method' => 'GET', 'path' => '/v1/subscriptions/{{sub_1}}', 'body' => []],
            'response' => ['status' => 200, 'body' => [
                'id' => '{{sub_1}}',
                'object' => 'subscription',
                'status' => $status,
                'current_period_end' => '{{t+14d1h}}',
            ]],
        ]],
    ], ['trialing', 'past_due'], ['0d', '14d1h']));

    app(SimulationEnvironment::class)->run($fixture, function (SimulationContext $context) {
        $context->enterStep(0);
        $first = Cashier::stripe()->subscriptions->retrieve('sub_replay1');

        expect($first->status)->toBe('trialing')
            ->and(CarbonImmutable::now()->toIso8601String())->toBe('2026-01-01T00:00:00+00:00');

        $context->enterStep(1);
        $second = Cashier::stripe()->subscriptions->retrieve('sub_replay1');
        $payload = $context->resolvePlaceholders(['id' => '{{sub_1}}', 'created' => '{{t+14d1h}}']);

        expect($second->status)->toBe('past_due')
            ->and($second->current_period_end)->toBe(1768438800)
            ->and($payload)->toBe(['id' => 'sub_replay1', 'created' => 1768438800])
            ->and(Carbon::now()->toIso8601String())->toBe('2026-01-15T01:00:00+00:00')
            ->and(CarbonImmutable::now()->toIso8601String())->toBe('2026-01-15T01:00:00+00:00')
            ->and($context->position()->toString())->toBe('14d1h');
    }, CarbonImmutable::parse('2026-01-01T00:00:00Z'));
});

it('gives the callback credentials, a billable and a clock', function () {
    $seen = environment()->run(simulationFixture(), fn (SimulationContext $context) => $context);

    expect($seen->credentials->secret)->toStartWith('sk_test_')
        ->and($seen->credentials->webhookSecret)->toStartWith('whsec_')
        ->and($seen->billable->id)->toBe(1);
});

it('defaults placeholders and both clocks to the same starting moment', function () {
    $before = CarbonImmutable::parse('2026-01-01T00:00:00Z');
    Carbon::setTestNow($before);
    CarbonImmutable::setTestNow($before);

    environment()->run(simulationFixture(), function (SimulationContext $context) {
        expect($context->startedAt->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and(Carbon::now()->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and(CarbonImmutable::now()->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')
            ->and($context->resolvePlaceholders(['created' => '{{t+1h}}']))->toBe(['created' => 1767229200]);
    });
});

it('hides the application real Stripe key for the length of the run', function () {
    // Not a nicety. While a replay is running the real key is not in
    // configuration to be read, so no code path can reach live Stripe by
    // accident — including code that has not been written yet.
    config()->set('cashier.secret', 'sk_live_realkey');

    $inside = environment()->run(simulationFixture(), fn () => config('cashier.secret'));

    expect($inside)->not->toBe('sk_live_realkey')
        ->and($inside)->toStartWith('sk_test_replay')
        ->and(config('cashier.secret'))->toBe('sk_live_realkey');
});

it('supplies a webhook secret so signature verification stays switched on', function () {
    // Cashier applies its signature middleware only when this is set. Leaving it
    // unset would silently skip the code most worth exercising.
    config()->set('cashier.webhook.secret', null);

    $inside = environment()->run(simulationFixture(), fn () => config('cashier.webhook.secret'));

    expect($inside)->toStartWith('whsec_')
        ->and(config('cashier.webhook.secret'))->toBeNull();
});

it('generates different credentials for every run', function () {
    $first = environment()->run(simulationFixture(), fn (SimulationContext $context) => $context->credentials->secret);
    $second = environment()->run(simulationFixture(), fn (SimulationContext $context) => $context->credentials->secret);

    expect($first)->not->toBe($second);
});

it('installs the replay transport and puts the original back', function () {
    $original = ApiRequestor::httpClient();
    $inside = environment()->run(simulationFixture(), fn () => ApiRequestor::httpClient());

    expect($inside)->toBeInstanceOf(FixtureHttpClient::class)
        ->and($inside)->not->toBe($original)
        ->and(ApiRequestor::httpClient())->toBe($original);
});

it('restores global state after a run', function (bool $throws, bool $frozen) {
    // The failure this prevents does not show up here. It shows up as an
    // unrelated test failing later, only in a certain order.
    $original = ApiRequestor::httpClient();
    config()->set('cashier.secret', 'sk_live_realkey');
    config()->set('cashier.webhook.secret', 'whsec_realkey');
    $mailers = config('mail.mailers');
    $before = $frozen ? CarbonImmutable::parse('2020-05-05T05:05:05Z') : null;
    Carbon::setTestNow($before);
    CarbonImmutable::setTestNow($before);
    $fixture = simulationFixture([['advance_to' => '30d', 'label' => 'later', 'events' => []]]);

    $run = fn () => environment()->run($fixture, function (SimulationContext $context) use ($throws) {
        $context->enterStep(0);

        if ($throws) {
            throw new RuntimeException('scenario blew up');
        }

        return 'completed';
    });

    if ($throws) {
        expect($run)->toThrow(RuntimeException::class, 'scenario blew up');
    } else {
        expect($run())->toBe('completed');
    }

    expect(ApiRequestor::httpClient())->toBe($original)
        ->and(config('cashier.secret'))->toBe('sk_live_realkey')
        ->and(config('cashier.webhook.secret'))->toBe('whsec_realkey')
        ->and(config('mail.mailers'))->toBe($mailers)
        ->and(Carbon::getTestNow())->toEqual($before)
        ->and(CarbonImmutable::getTestNow())->toEqual($before);
})->with([
    'success' => [false, false],
    'exception' => [true, false],
    'success with frozen clocks' => [false, true],
    'exception with frozen clocks' => [true, true],
]);

it('moves both Carbon clocks together', function () {
    // Application code reads whichever it prefers. Advancing one would leave
    // now() disagreeing with itself in the middle of a dunning window.
    $fixture = simulationFixture([['advance_to' => '14d1h', 'label' => 'later', 'events' => []]]);
    [$mutable, $immutable] = environment()->run($fixture, function (SimulationContext $context) {
        $context->enterStep(0);

        return [Carbon::now(), CarbonImmutable::now()];
    }, CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($mutable->toIso8601String())->toBe('2026-01-15T01:00:00+00:00')
        ->and($immutable->toIso8601String())->toBe('2026-01-15T01:00:00+00:00');
});

it('leaves the clock where it found it', function () {
    $before = CarbonImmutable::parse('2020-05-05T05:05:05Z');
    Carbon::setTestNow($before);
    CarbonImmutable::setTestNow($before);

    $fixture = simulationFixture([['advance_to' => '30d', 'label' => 'later', 'events' => []]]);
    environment()->run($fixture, fn (SimulationContext $context) => $context->enterStep(0));

    expect(Carbon::now()->toIso8601String())->toBe($before->toIso8601String());
});

it('reports its position in the timeline', function () {
    $fixture = simulationFixture([['advance_to' => '21d', 'label' => 'later', 'events' => []]]);
    $position = environment()->run($fixture, function (SimulationContext $context) {
        $context->enterStep(0);

        return $context->position();
    });

    expect($position->toString())->toBe('21d');
});

it('refuses to nest', function () {
    // The environment swaps global state. A nested run would restore the inner
    // values on the way out and leave the outer run in a world it did not make.
    environment()->run(simulationFixture(), fn () => environment()->run(simulationFixture(), fn () => null));
})->throws(SimulationFailed::class, 'already running');

it('is runnable again after a nested attempt is refused', function () {
    try {
        environment()->run(simulationFixture(), fn () => environment()->run(simulationFixture(), fn () => null));
    } catch (SimulationFailed) {
        // expected
    }

    expect(environment()->run(simulationFixture(), fn () => 'ran again'))->toBe('ran again');
});

it('explains how to register a billable when it cannot build one', function () {
    CashierDunning::flush();
    Cashier::useCustomerModel(Authenticatable::class);

    environment()->run(simulationFixture(), fn () => null);
})->throws(SimulationFailed::class, 'createBillableUsing');

it('rejects a billable factory that does not return a model', function () {
    CashierDunning::createBillableUsing(fn () => 'a user, honest');

    environment()->run(simulationFixture(), fn () => null);
})->throws(SimulationFailed::class, 'returned string');

it('intercepts a real Cashier Stripe call without touching the network', function () {
    // The load-bearing claim of the whole package. Laravel's Http::fake() cannot
    // see these calls: the Stripe SDK ships its own CurlClient and never goes
    // through Laravel's HTTP stack. ApiRequestor::setHttpClient() is the only
    // seam, and this proves it covers the StripeClient that Cashier actually
    // uses, not just the SDK's legacy static calls.
    $fixture = simulationFixture([[
        'advance_to' => '0d',
        'label' => 'customer',
        'events' => [],
        'api_responses' => [[
            'match' => ['method' => 'GET', 'path' => '/v1/customers/cus_anything', 'body' => []],
            'response' => ['status' => 200, 'body' => ['id' => 'cus_replayed', 'object' => 'customer']],
        ]],
    ]]);

    $customer = environment()->run(
        $fixture,
        fn () => Cashier::stripe()->customers->retrieve('cus_anything')
    );

    expect($customer->id)->toBe('cus_replayed');
});

it('records the side effects of a run and refuses their delivery', function () {
    config()->set('mail.default', 'array');

    [$sideEffects, $blocked] = environment()->run(simulationFixture(), function (SimulationContext $context): array {
        Mail::raw('dunning', fn ($message) => $message->to('jenny@example.com'));
        Mail::raw('dunning', fn ($message) => $message->to('jenny@example.com'));

        return [$context->sideEffects(), $context->blockedDeliveries()];
    });

    expect($sideEffects)->toBe(['mail:jenny@example.com' => 2])
        ->and($blocked)->toBe(2)
        ->and(Mail::getSymfonyTransport()->messages()->count())->toBe(0);
});

it('starts every run with no side effects', function () {
    config()->set('mail.default', 'array');

    environment()->run(simulationFixture(), fn () => Mail::raw('first', fn ($message) => $message->to('first@example.com')));

    expect(environment()->run(simulationFixture(), fn (SimulationContext $context): array => $context->sideEffects()))->toBe([]);
});

it('observes nothing outside a run', function () {
    config()->set('mail.default', 'array');

    $context = environment()->run(simulationFixture(), fn (SimulationContext $context) => $context);

    Mail::raw('after', fn ($message) => $message->to('after@example.com'));

    expect($context->sideEffects())->toBe([]);
});

it('registers its listeners once, however many runs there are', function () {
    environment()->run(simulationFixture(), fn () => null);
    $listeners = count(Event::getListeners(MessageSending::class));

    environment()->run(simulationFixture(), fn () => null);
    environment()->run(simulationFixture(), fn () => null);

    expect(count(Event::getListeners(MessageSending::class)))->toBe($listeners);
});
