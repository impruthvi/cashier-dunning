<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Tests\Support\User;
use Impruthvi\CashierDunning\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A billable whose Stripe id matches the one placeholders resolve to, so
 * Cashier's webhook controller can find it.
 */
function billableUser(): User
{
    $user = User::create([
        'name' => 'Jenny',
        'email' => 'jenny@example.com',
        'stripe_id' => 'cus_replay1',
    ]);

    CashierDunning::createBillableUsing(fn () => $user);

    return $user;
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
