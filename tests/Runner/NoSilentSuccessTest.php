<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

/** @param list<array<string, mixed>> $steps */
function fixtureOf(array $steps): Fixture
{
    return Fixture::fromArray([
        'format_version' => 1,
        'provider' => 'stripe',
        'scenario' => 'silent',
        'provenance' => ['synthetic' => true],
        'manifest' => ['required_events' => [], 'optional_events' => []],
        'steps' => $steps,
    ]);
}

it('refuses to call a run that asserted nothing a pass', function () {
    // The specific failure this package exists to prevent. A green billing test
    // that proves nothing is worse than no test, because it stops anyone looking.
    User::create(['email' => 'a@b.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => User::first());

    $report = (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))->run(
        fixtureOf([['advance_to' => '0d', 'label' => 'nothing happens', 'events' => []]]),
        CarbonImmutable::parse('2026-01-01T00:00:00Z')
    );

    expect($report->assertions)->toBe(0)
        ->and($report->failures())->toBe([])
        ->and($report->passed())->toBeFalse()
        ->and($report->verdict())->toStartWith('No assertions ran.');
});

it('counts every delivered event as an assertion', function () {
    // Delivering an event Stripe really sent is itself a claim: the application
    // has to accept it. A 500 here is a bug whether or not entitlements moved.
    User::create(['email' => 'a@b.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => User::first());

    $report = (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))->run(
        fixtureOf([[
            'advance_to' => '0d',
            'label' => 'ping',
            'events' => [
                ['id' => '{{evt_1}}', 'type' => 'ping', 'data' => ['object' => []]],
                ['id' => '{{evt_2}}', 'type' => 'ping', 'data' => ['object' => []]],
            ],
        ]]),
        CarbonImmutable::parse('2026-01-01T00:00:00Z')
    );

    expect($report->assertions)->toBe(2)
        ->and($report->passed())->toBeTrue();
});
