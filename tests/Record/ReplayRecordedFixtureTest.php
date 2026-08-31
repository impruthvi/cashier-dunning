<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

/**
 * The loop closing. This fixture was recorded against a real Stripe test
 * account by the recorder in this package, and is replayed here with no
 * account, no key and no network.
 *
 * A recorder whose output nobody replays is a recorder that produces plausible
 * files. This is the test that says the two halves fit.
 */
it('replays a fixture this package recorded from real Stripe', function () {
    $user = User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => $user);

    $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate-recorded');

    $report = (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))->run(
        $fixture,
        CarbonImmutable::parse('2026-01-01T00:00:00Z')
    );

    // Counted from the fixture rather than hard-coded: the assertion is that
    // every recorded event was delivered and accepted, not that the recording
    // happened to be a particular length.
    expect($report->passed())->toBeTrue()
        ->and($report->failures())->toBe([])
        ->and($report->eventsDelivered())->toBe(count($fixture->eventTypes()))
        ->and($report->eventsDelivered())->toBeGreaterThan(20);
});

it('drives Cashier to the state real Stripe ended in', function () {
    // Real Stripe cancelled the subscription during the sixth step, not the
    // fifth — the hand-authored timeline guessed wrong about when Stripe gives
    // up, and the recording corrected it.
    $user = User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);
    CashierDunning::createBillableUsing(fn () => $user);

    (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))->run(
        (new FixtureRepository)->find('trial-dunning-cancel-reactivate-recorded'),
        CarbonImmutable::parse('2026-01-01T00:00:00Z')
    );

    $subscriptions = $user->subscriptions()->reorder('id')->get();

    expect($subscriptions)->toHaveCount(2)
        ->and($subscriptions[0]->stripe_status)->toBe('canceled')
        ->and($subscriptions[1]->stripe_status)->toBe('active');
});

it('carries only events the scenario declared', function () {
    // Real Stripe emitted 76 events across this timeline; 50 of them had no
    // allowlist rules and would have survived as husks carrying nothing but an
    // id. The manifest is what keeps a fixture reviewable.
    $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate-recorded');

    expect(array_unique($fixture->eventTypes()))->each->toBeIn([
        'customer.subscription.created',
        'customer.subscription.trial_will_end',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.created',
        'invoice.finalized',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_succeeded',
    ]);
});

it('records what the account emitted that nobody asked about', function () {
    // Dropped from the fixture, kept in provenance. That is how a manifest
    // learns as Stripe changes, rather than by someone noticing years later.
    $provenance = (new FixtureRepository)->find('trial-dunning-cancel-reactivate-recorded')->provenance;

    expect($provenance['undeclared_events'])->toContain('charge.failed')
        ->and($provenance['undeclared_events'])->toContain('setup_intent.succeeded')
        ->and($provenance['synthetic'])->toBeTrue();
});
