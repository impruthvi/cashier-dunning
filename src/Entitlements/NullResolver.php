<?php

namespace Impruthvi\CashierDunning\Entitlements;

use Impruthvi\CashierDunning\Contracts\EntitlementResolver;

/**
 * The default when an application has registered nothing.
 *
 * Returning an empty snapshot rather than throwing is deliberate: the first
 * thing a new adopter does is run a scenario, and subscription status, dunning
 * transitions and webhook ordering are all still worth watching before any
 * entitlements exist. The runner renders the entitlement column as empty and
 * says how to fill it.
 */
final readonly class NullResolver implements EntitlementResolver
{
    /** @return array<string, scalar|null> */
    public function resolve(object $billable): array
    {
        return [];
    }
}
