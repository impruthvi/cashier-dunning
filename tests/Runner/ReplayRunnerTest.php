<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\User;

afterEach(fn () => CashierDunning::flush());

function runner(): ReplayRunner
{
    return new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class));
}

function billableUser(): User
{
    $user = User::create(['name' => 'Jenny', 'email' => 'jenny@example.com', 'stripe_id' => 'cus_replay1']);

    CashierDunning::createBillableUsing(fn () => $user);

    return $user;
}

/**
 * The dunning policy these tests assert against: access survives the retry
 * window and ends when Stripe gives up. Cashier's subscription row cannot
 * express that on its own, which is the reason the resolver contract exists.
 */
function registerDunningPolicy(): void
{
    CashierDunning::resolveEntitlementsUsing(function (User $billable): array {
        $billable->refresh();
        $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

        $entitled = $subscription !== null
            && ! $billable->dunning_exhausted
            && $subscription->ends_at === null
            && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

        return ['teams' => $entitled, 'api' => $entitled, 'projects' => $entitled ? 10 : 0];
    });
}

function shippedDunningFixture(): Fixture
{
    return (new FixtureRepository)->find('trial-dunning-cancel-reactivate');
}

$start = CarbonImmutable::parse('2026-01-01T00:00:00Z');

it('replays the shipped dunning fixture end to end with no Stripe account', function () use ($start) {
    // The claim the package exists to make. Six steps, a month of billing, a
    // failed payment, a cancellation and a recovery — no key, no network.
    billableUser();
    registerDunningPolicy();

    $report = runner()->run(shippedDunningFixture(), $start);

    expect($report->passed())->toBeTrue()
        ->and($report->verdict())->toBe('All 25 assertions passed.')
        ->and($report->steps)->toHaveCount(6)
        ->and($report->eventsDelivered())->toBe(7)
        ->and($report->failures())->toBe([]);
});

it('drives real Cashier state through the whole lifecycle', function () use ($start) {
    // Not a simulation of Cashier: these are rows Cashier's own webhook
    // controller wrote, in response to events it verified the signature of.
    $user = billableUser();
    registerDunningPolicy();

    runner()->run(shippedDunningFixture(), $start);

    // reorder(), because Cashier's relation already sorts by created_at desc
    // and every subscription here was created in the same simulated second.
    $subscriptions = $user->subscriptions()->reorder('id')->get();

    expect($subscriptions)->toHaveCount(2)
        ->and($subscriptions[0]->stripe_id)->toBe('sub_replay1')
        ->and($subscriptions[0]->stripe_status)->toBe('canceled')
        ->and($subscriptions[1]->stripe_id)->toBe('sub_replay2')
        ->and($subscriptions[1]->stripe_status)->toBe('active');
});

it('attributes each entitlement change to the event that caused it', function () use ($start) {
    // Decision 1B, earning its keep. Step 2 delivers two events; only one of
    // them moved anything, and the report says which.
    billableUser();
    registerDunningPolicy();

    $report = runner()->run(shippedDunningFixture(), $start);

    $changes = [];

    foreach ($report->steps as $step) {
        foreach ($step->events as $event) {
            foreach ($event->changes as $change) {
                $changes[] = $change->describe();
            }
        }
    }

    expect($changes)->toContain('api: true -> false (at invoice.payment_failed)')
        ->and($changes)->toContain('api: false -> true (at customer.subscription.created)')
        ->and($changes)->not->toContain('api: true -> false (at customer.subscription.updated)');
});

it('reports the losses a dunning scenario is actually about', function () use ($start) {
    billableUser();
    registerDunningPolicy();

    $report = runner()->run(shippedDunningFixture(), $start);

    $losses = [];

    foreach ($report->steps as $step) {
        foreach ($step->events as $event) {
            foreach ($event->changes as $change) {
                if ($change->isLoss()) {
                    $losses[] = $change->feature.' at '.$change->eventType;
                }
            }
        }
    }

    expect($losses)->toContain('teams at invoice.payment_failed')
        ->and($losses)->toContain('projects at invoice.payment_failed');
});

it('keeps access alive through the retry window', function () use ($start) {
    // The bug this package is named after. An application that revokes on the
    // first failed payment loses customers Stripe would have recovered, and
    // this is the step that catches it.
    billableUser();
    registerDunningPolicy();

    $report = runner()->run(shippedDunningFixture(), $start);

    expect($report->steps[2]->label)->toBe('trial ends, first payment attempt fails')
        ->and($report->steps[2]->actualEntitlements['teams'])->toBeTrue()
        ->and($report->steps[3]->actualEntitlements['teams'])->toBeFalse();
});

it('fails when the application revokes access too early', function () use ($start) {
    // The same fixture against a stricter application. Nothing about the
    // recording changes; the run turns red because the app does not match it.
    billableUser();
    CashierDunning::resolveEntitlementsUsing(function (User $billable): array {
        $billable->refresh();
        $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

        $entitled = $subscription !== null
            && $subscription->ends_at === null
            && in_array($subscription->stripe_status, ['trialing', 'active'], true);

        return ['teams' => $entitled, 'api' => $entitled, 'projects' => $entitled ? 10 : 0];
    });

    $report = runner()->run(shippedDunningFixture(), $start);

    expect($report->passed())->toBeFalse()
        ->and($report->failures())->toHaveCount(1)
        ->and($report->failures()[0]->label)->toBe('trial ends, first payment attempt fails')
        ->and($report->failures()[0]->mismatches[0])
        ->toBe('teams: recording says true, application says false');
});

it('stops at the first failing step', function () use ($start) {
    // Once state has diverged from the recording, later steps compare against a
    // world the fixture never described. Those failures bury the first one.
    billableUser();
    CashierDunning::resolveEntitlementsUsing(fn (): array => ['teams' => false]);

    $report = runner()->run(shippedDunningFixture(), $start);

    expect($report->steps)->toHaveCount(1)
        ->and($report->verdict())
        ->toBe('1 step(s) did not match the recording, starting at step 0 (trial starts).');
});

it('runs without an entitlement resolver', function () use ($start) {
    // Subscription status and webhook handling are worth watching before any
    // entitlements exist. The events still assert; the comparison simply has
    // nothing to compare.
    billableUser();

    $report = runner()->run(shippedDunningFixture(), $start);

    expect($report->passed())->toBeFalse()
        ->and($report->steps[0]->mismatches)->not->toBe([]);
});
