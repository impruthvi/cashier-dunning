<?php

namespace Impruthvi\CashierDunning\Entitlements;

use Closure;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\Exceptions\InvalidResolver;

/**
 * Adapts a closure to the resolver contract, so an application can register
 * entitlements in a service provider without declaring a class.
 *
 * The return value is validated on every call rather than trusted. A resolver
 * that returns an Eloquent collection or a nested array still "works" until a
 * fixture is written, at which point the failure surfaces as an unreadable diff
 * far from its cause.
 */
final readonly class CallbackResolver implements EntitlementResolver
{
    public function __construct(private Closure $callback) {}

    /** @return array<string, scalar|null> */
    public function resolve(object $billable): array
    {
        $result = ($this->callback)($billable);

        if (! is_array($result)) {
            throw InvalidResolver::returnedNonArray(get_debug_type($result));
        }

        foreach ($result as $feature => $value) {
            if (! is_string($feature)) {
                throw InvalidResolver::returnedNonStringKey((string) $feature);
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidResolver::returnedNonScalar($feature, get_debug_type($value));
            }
        }

        /** @var array<string, scalar|null> $result */
        return $result;
    }
}
