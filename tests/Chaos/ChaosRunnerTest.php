<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Chaos\ChaosReport;
use Impruthvi\CashierDunning\Chaos\ChaosRunner;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;

beforeEach(fn () => DunningMailer::reset());
afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

function chaos(bool $shuffle, bool $duplicate, int $iterations = 2): ChaosReport
{
    billableUser();
    registerDunningPolicy();

    return (new ChaosRunner(
        new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)),
        app(Dispatcher::class),
        app('db')->connection(),
    ))->run(
        fixture: (new FixtureRepository)->find('trial-dunning-cancel-reactivate'),
        shuffle: $shuffle,
        duplicate: $duplicate,
        seed: 1234,
        iterations: $iterations,
        startingAt: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
    );
}

it('catches an application that emails a customer twice', function () {
    // The whole point. Stripe delivers at least once, so this application emails
    // some customers twice — and still ends the run with a perfectly correct
    // subscription row. Comparing end state alone would call it idempotent.
    DunningMailer::$deduplicate = false;

    $report = chaos(shuffle: false, duplicate: true, iterations: 1);

    expect($report->passed())->toBeFalse()
        ->and($report->failures())->toHaveCount(1)
        ->and($report->failures()[0]->divergences[0])->toContain('mail:jenny@example.com')
        ->and($report->verdict())->toContain('--seed=1234');
});

it('passes the same application once it remembers what it has handled', function () {
    // Same fixture, same orderings, one line of deduplication in the app.
    DunningMailer::$deduplicate = true;

    $report = chaos(shuffle: false, duplicate: true, iterations: 1);

    expect($report->passed())->toBeTrue()
        ->and($report->verdict())->toContain('behaved identically');
});

it('replays the timeline in order first', function () {
    DunningMailer::$deduplicate = true;

    $report = chaos(shuffle: true, duplicate: false, iterations: 2);

    expect($report->passes[0]->ordering)->toBe('in order')
        ->and($report->passes[0]->pass)->toBe(0)
        ->and($report->passes)->toHaveCount(3);
});

it('survives its own recording being delivered out of order', function () {
    // Stripe says a subscription may be deleted before its creation event
    // arrives. Cashier and this application hold up.
    DunningMailer::$deduplicate = true;

    expect(chaos(shuffle: true, duplicate: false, iterations: 3)->passed())->toBeTrue();
});

it('reports a shuffle over single-event steps as not applicable', function () {
    // Shuffling one event is a no-op, and calling that a passing chaos run
    // would be coverage theatre.
    billableUser();

    $fixture = Fixture::fromArray([
        'format_version' => 1,
        'provider' => 'stripe',
        'scenario' => 'single',
        'provenance' => ['synthetic' => true],
        'manifest' => ['required_events' => [], 'optional_events' => []],
        'steps' => [[
            'advance_to' => '0d',
            'label' => 'one event',
            'events' => [['id' => '{{evt_1}}', 'type' => 'ping', 'data' => ['object' => []]]],
        ]],
    ]);

    $report = (new ChaosRunner(
        new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)),
        app(Dispatcher::class),
        app('db')->connection(),
    ))->run($fixture, shuffle: true, duplicate: false, seed: 1, iterations: 2);

    expect($report->applicablePasses())->toBe(1)
        ->and($report->passes[1]->notApplicable)->toBeTrue()
        ->and($report->passed())->toBeTrue();
});

it('names the seed so a failing ordering can be run again', function () {
    DunningMailer::$deduplicate = false;

    expect(chaos(shuffle: true, duplicate: true, iterations: 1)->seed)->toBe(1234);
});
