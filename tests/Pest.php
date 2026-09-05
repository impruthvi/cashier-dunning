<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Tests\Support\User;
use Impruthvi\CashierDunning\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Register the README's factory-per-call setup. Creating a billable here and
 * capturing it would hide collisions between consecutive replay invocations.
 */
function billableUser(): void
{
    CashierDunning::createBillableUsing(fn () => User::factory()->create([
        'stripe_id' => 'cus_replay1',
    ]));
}

/**
 * The dunning policy the shipped fixtures were recorded against: access
 * survives the retry window and ends when Stripe gives up.
 *
 * Cashier's subscription row cannot express this on its own — it reads
 * `past_due` both before and after the final retry — which is the reason the
 * resolver contract exists.
 */
function registerDunningPolicy(): void
{
    CashierDunning::resolveEntitlementsUsing(
        function (User $billable): array {
            $billable->refresh();
            $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

            $entitled = $subscription !== null
                && ! $billable->dunning_exhausted
                && $subscription->ends_at === null
                && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

            return ['teams' => $entitled, 'api' => $entitled, 'projects' => $entitled ? 10 : 0];
        }
    );
}

/**
 * A plan-aware policy, for the downgrade scenario.
 *
 * Keyed on the placeholder ids a replay resolves to rather than on Stripe
 * lookup keys: a fixture stores `{{price_1}}`, and by the time the application
 * sees it, it is `price_replay1`. Placeholders are assigned in first-seen
 * order, so the plan subscribed to first is `price_replay1` and the one
 * downgraded to is `price_replay2` — which is exactly the mapping a real
 * application keeps between its own Stripe price ids and its plans.
 */
function registerPlanLimits(): void
{
    CashierDunning::resolveEntitlementsUsing(
        function (User $billable): array {
            $billable->refresh();
            $subscription = $billable->subscriptions()->where('type', 'default')->latest('id')->first();

            $entitled = $subscription !== null
                && $subscription->ends_at === null
                && in_array($subscription->stripe_status, ['trialing', 'active', 'past_due'], true);

            // Placeholders are assigned in first-seen order, so the plan
            // subscribed to first is price_replay1 and the one downgraded to is
            // price_replay2 — the same mapping a real application keeps between
            // its Stripe price ids and its plans.
            $limits = ['price_replay1' => 25, 'price_replay2' => 3];

            return [
                'projects' => $entitled ? ($limits[$subscription?->stripe_price ?? ''] ?? 0) : 0,
                'api' => $entitled,
            ];
        }
    );
}
