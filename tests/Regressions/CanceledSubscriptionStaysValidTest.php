<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\User;
use Laravel\Cashier\Subscription;

/**
 * laravel/cashier-stripe#1791 — a subscription Stripe has canceled reports
 * itself as both canceled and active, and `subscribed()` keeps returning true.
 *
 * Reported 2025-10-22, still open, no reproduction attached. It reproduces on
 * Cashier 16.8.0 with no Stripe account, no API key and no network.
 *
 * The mechanism is a disagreement between two of Cashier's own webhook
 * handlers. `customer.subscription.deleted` calls `markAsCanceled()`, which
 * writes `ends_at = now()`, so the grace period is already over and `active()`
 * is false. `customer.subscription.updated` carrying `status: canceled` takes
 * a different path (WebhookController.php:173):
 *
 *     if ($data['cancel_at_period_end'] ?? false) {
 *         $subscription->ends_at = ... $subscription->currentPeriodEnd();
 *
 * and Stripe documents `cancel_at_period_end` as "whether this subscription
 * will (if status=active) or *did* (if status=canceled) cancel at the end of
 * the current billing period" — so it is still true on the canceled object.
 * `ends_at` therefore lands at the period end, which is in the future when
 * Stripe cancels early, and `onGracePeriod()` is true. From there:
 *
 *     canceled()  = ! is_null($ends_at)              -> true
 *     ended()     = canceled() && ! onGracePeriod()  -> false
 *     active()    = ! ended() && ...                 -> TRUE
 *     valid()     = active() || ...                  -> TRUE
 *
 * Nothing in `valid()` consults `stripe_status`, which is the reporter's exact
 * complaint. The customer keeps paid access to a subscription Stripe has
 * stopped billing.
 */
beforeEach(function () {
    billableUser();

    // Cashier's documented way to ask whether a customer has access. The
    // application here is not doing anything clever or wrong — this is the
    // line from the Laravel billing docs.
    CashierDunning::resolveEntitlementsUsing(function (User $billable): array {
        $billable->refresh();
        $billable->unsetRelation('subscriptions');

        return ['access' => $billable->subscribed('default')];
    });
});

afterEach(fn () => CashierDunning::flush());

function replayEarlyCancellation(): ReplayReport
{
    return (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))->run(
        (new FixtureRepository)->find('cancel-at-period-end-then-canceled-early'),
        CarbonImmutable::parse('2026-01-01T00:00:00Z')
    );
}

it('keeps granting access after Stripe cancels the subscription', function () {
    $report = replayEarlyCancellation();

    // Every webhook was accepted. Cashier answered 200 to all three and wrote
    // a subscription row that looks fine. Nothing here is a crash.
    expect($report->eventsDelivered())->toBe(3);

    // Access survives the request to cancel at period end, which is correct:
    // the customer paid through day 30.
    expect($report->steps[1]->mismatches)->toBe([]);

    // And survives Stripe actually cancelling, which is not.
    expect($report->passed())->toBeFalse()
        ->and($report->steps[2]->mismatches)->toBe([
            'access: recording says false, application says true',
        ]);
});

it('reports the subscription as canceled and active at the same time', function () {
    // The reporter's words: "Active and Canceled both return true". Read off
    // the live model rather than inferred from the entitlement mismatch, so
    // the failure names the methods a maintainer would go and look at.
    $observed = [];

    $policy = CashierDunning::entitlementResolver();

    CashierDunning::resolveEntitlementsUsing(function (User $billable) use ($policy, &$observed): array {
        $billable->refresh();
        $billable->unsetRelation('subscriptions');

        $subscription = $billable->subscription('default');

        if ($subscription instanceof Subscription) {
            $observed = [
                'stripe_status' => $subscription->stripe_status,
                'ends_at_is_future' => (bool) $subscription->ends_at?->isFuture(),
                'canceled' => $subscription->canceled(),
                'active' => $subscription->active(),
                'ended' => $subscription->ended(),
                'valid' => $subscription->valid(),
            ];
        }

        return $policy->resolve($billable);
    });

    replayEarlyCancellation();

    expect($observed)->toBe([
        'stripe_status' => 'canceled',
        'ends_at_is_future' => true,
        'canceled' => true,
        'active' => true,
        'ended' => false,
        'valid' => true,
    ]);
});
