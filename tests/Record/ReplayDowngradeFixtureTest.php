<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

function downgradeFixture(): Fixture
{
    return (new FixtureRepository)->find('downgrade-over-usage-limit-recorded');
}

function replayDowngrade(): ReplayReport
{
    billableUser();
    registerPlanLimits();

    return (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))
        ->run(downgradeFixture(), CarbonImmutable::parse('2026-01-01T00:00:00Z'));
}

it('replays the downgrade recorded from real Stripe', function () {
    $report = replayDowngrade();

    expect($report->passed())->toBeTrue()
        ->and($report->failures())->toBe([]);
});

it('shows the limit dropping the moment the plan changes', function () {
    // The whole scenario in one assertion: 25 projects on the larger plan, 3 on
    // the smaller one, and the change lands on the step where the customer
    // actually downgraded rather than at the next renewal.
    $projects = array_map(
        static fn ($step): ?int => $step->entitlements['projects'] ?? null,
        downgradeFixture()->steps
    );

    expect($projects)->toBe([25, 25, 3, 3, 3]);
});

it('keeps the limit low through the period the customer already paid for', function () {
    // Worth recording rather than assuming. An application that restored the
    // larger limit until the period ended — on the reasonable-sounding grounds
    // that the customer paid for it — would diverge here, and this fixture is
    // what would catch it.
    $steps = downgradeFixture()->steps;

    expect($steps[3]->label)->toBe('still inside the period they paid the higher price for')
        ->and($steps[3]->entitlements['projects'])->toBe(3);
});

it('numbers placeholders densely so a reviewer can tell the plans apart', function () {
    // Redaction runs before normalisation. The other order let fields that were
    // about to be dropped consume placeholder numbers, and the fixture came out
    // with {{price_1}} and {{price_4}} and no explanation for the gap.
    $prices = [];

    foreach (downgradeFixture()->steps as $step) {
        foreach ($step->events as $event) {
            foreach ($event['data']['object']['items']['data'] ?? [] as $item) {
                $prices[] = $item['price']['id'];
            }
        }
    }

    expect(array_values(array_unique($prices)))->toBe(['{{price_1}}', '{{price_2}}']);
});

it('drives Cashier onto the smaller plan', function () {
    replayDowngrade();

    $subscription = User::first()
        ->subscriptions()->where('type', 'default')->first();

    expect($subscription->stripe_status)->toBe('active')
        ->and($subscription->stripe_price)->toBe('price_replay2');
});
