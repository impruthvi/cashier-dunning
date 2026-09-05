<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Fixtures\Duration;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Simulation\SimulationContext;
use Impruthvi\CashierDunning\Simulation\SimulationEnvironment;
use Laravel\Cashier\Cashier;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    CashierDunning::createBillableUsing(fn () => (object) ['id' => 1]);
});

afterEach(function () {
    CashierDunning::flush();
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function environment(?ClientInterface $client = null): SimulationEnvironment
{
    return new SimulationEnvironment(config(), $client);
}

function recordingClient(): ClientInterface
{
    return new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'standard', $maxNetworkRetries = null)
        {
            return ['{}', 200, []];
        }
    };
}

it('gives the callback credentials, a billable and a clock', function () {
    $seen = environment()->run(fn (SimulationContext $context) => $context);

    expect($seen->credentials->secret)->toStartWith('sk_test_')
        ->and($seen->credentials->webhookSecret)->toStartWith('whsec_')
        ->and($seen->billable->id)->toBe(1);
});

it('hides the application real Stripe key for the length of the run', function () {
    // Not a nicety. While a replay is running the real key is not in
    // configuration to be read, so no code path can reach live Stripe by
    // accident — including code that has not been written yet.
    config()->set('cashier.secret', 'sk_live_realkey');

    $inside = environment()->run(fn () => config('cashier.secret'));

    expect($inside)->not->toBe('sk_live_realkey')
        ->and($inside)->toStartWith('sk_test_replay')
        ->and(config('cashier.secret'))->toBe('sk_live_realkey');
});

it('supplies a webhook secret so signature verification stays switched on', function () {
    // Cashier applies its signature middleware only when this is set. Leaving it
    // unset would silently skip the code most worth exercising.
    config()->set('cashier.webhook.secret', null);

    $inside = environment()->run(fn () => config('cashier.webhook.secret'));

    expect($inside)->toStartWith('whsec_')
        ->and(config('cashier.webhook.secret'))->toBeNull();
});

it('generates different credentials for every run', function () {
    $first = environment()->run(fn (SimulationContext $c) => $c->credentials->secret);
    $second = environment()->run(fn (SimulationContext $c) => $c->credentials->secret);

    expect($first)->not->toBe($second);
});

it('installs the replay transport and puts the original back', function () {
    $original = ApiRequestor::httpClient();
    $replay = recordingClient();

    $inside = environment($replay)->run(fn () => ApiRequestor::httpClient());

    expect($inside)->toBe($replay)
        ->and(ApiRequestor::httpClient())->toBe($original);
});

it('restores global state when the callback throws', function () {
    // The failure this prevents does not show up here. It shows up as an
    // unrelated test failing later, only in a certain order.
    $original = ApiRequestor::httpClient();
    config()->set('cashier.secret', 'sk_live_realkey');

    try {
        environment(recordingClient())->run(function () {
            throw new RuntimeException('scenario blew up');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ApiRequestor::httpClient())->toBe($original)
        ->and(config('cashier.secret'))->toBe('sk_live_realkey')
        ->and(Carbon::hasTestNow())->toBeFalse();
});

it('moves both Carbon clocks together', function () {
    // Application code reads whichever it prefers. Advancing one would leave
    // now() disagreeing with itself in the middle of a dunning window.
    [$mutable, $immutable] = environment()->run(function (SimulationContext $context) {
        $context->advanceTo(Duration::parse('14d1h'));

        return [Carbon::now(), CarbonImmutable::now()];
    }, CarbonImmutable::parse('2026-01-01T00:00:00Z'));

    expect($mutable->toIso8601String())->toBe('2026-01-15T01:00:00+00:00')
        ->and($immutable->toIso8601String())->toBe('2026-01-15T01:00:00+00:00');
});

it('leaves the clock where it found it', function () {
    $before = CarbonImmutable::parse('2020-05-05T05:05:05Z');
    Carbon::setTestNow($before);
    CarbonImmutable::setTestNow($before);

    environment()->run(fn (SimulationContext $c) => $c->advanceTo(Duration::parse('30d')));

    expect(Carbon::now()->toIso8601String())->toBe($before->toIso8601String());
});

it('reports its position in the timeline', function () {
    $position = environment()->run(function (SimulationContext $context) {
        $context->advanceTo(Duration::parse('21d'));

        return $context->position();
    });

    expect($position->toString())->toBe('21d');
});

it('refuses to nest', function () {
    // The environment swaps global state. A nested run would restore the inner
    // values on the way out and leave the outer run in a world it did not make.
    environment()->run(fn () => environment()->run(fn () => null));
})->throws(SimulationFailed::class, 'already running');

it('is runnable again after a nested attempt is refused', function () {
    try {
        environment()->run(fn () => environment()->run(fn () => null));
    } catch (SimulationFailed) {
        // expected
    }

    expect(environment()->run(fn () => 'ran again'))->toBe('ran again');
});

it('explains how to register a billable when it cannot build one', function () {
    CashierDunning::flush();
    Cashier::useCustomerModel(Authenticatable::class);

    environment()->run(fn () => null);
})->throws(SimulationFailed::class, 'createBillableUsing');

it('rejects a billable factory that does not return a model', function () {
    CashierDunning::createBillableUsing(fn () => 'a user, honest');

    environment()->run(fn () => null);
})->throws(SimulationFailed::class, 'returned string');

it('intercepts a real Cashier Stripe call without touching the network', function () {
    // The load-bearing claim of the whole package. Laravel's Http::fake() cannot
    // see these calls: the Stripe SDK ships its own CurlClient and never goes
    // through Laravel's HTTP stack. ApiRequestor::setHttpClient() is the only
    // seam, and this proves it covers the StripeClient that Cashier actually
    // uses, not just the SDK's legacy static calls.
    $calls = [];

    $client = new class($calls) implements ClientInterface
    {
        /** @param array<int, array<string, mixed>> $calls */
        public function __construct(public array &$calls) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'standard', $maxNetworkRetries = null)
        {
            $this->calls[] = ['method' => $method, 'url' => $absUrl];

            return ['{"id": "cus_replayed", "object": "customer"}', 200, []];
        }
    };

    $customer = environment($client)->run(
        fn () => Cashier::stripe()->customers->retrieve('cus_anything')
    );

    expect($customer->id)->toBe('cus_replayed')
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['method'])->toBe('get')
        ->and($calls[0]['url'])->toContain('/v1/customers/cus_anything');
});
