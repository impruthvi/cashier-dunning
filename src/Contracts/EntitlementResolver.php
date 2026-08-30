<?php

namespace Impruthvi\CashierDunning\Contracts;

/**
 * Answers "what may this billable do right now?" at a point in a replayed
 * timeline.
 *
 * This package ships the contract and nothing behind it. Entitlements are a
 * domain decision — plan matrices, seat counts, grandfathered pricing, per-org
 * overrides — and every application answers them differently. Guessing here
 * would produce a resolver every adopter has to fight.
 *
 * What the package does own is *when* the question is asked: once after every
 * webhook event, not once per step. A step that delivers `invoice.payment_failed`
 * and `customer.subscription.updated` together changes entitlements at one of
 * those two events, and `--explain` can only name which one if the resolver is
 * called between them.
 *
 * Implementations must be pure with respect to the billable's current state:
 * called twice with no event in between, they must return the same snapshot.
 * A resolver that reads the wall clock, or that mutates on read, makes a
 * timeline unattributable.
 */
interface EntitlementResolver
{
    /**
     * @param  object  $billable  the Cashier billable, usually a User or Team model
     * @return array<string, scalar|null> feature name => current value
     */
    public function resolve(object $billable): array;
}
