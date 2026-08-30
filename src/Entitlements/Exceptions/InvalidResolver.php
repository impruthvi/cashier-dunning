<?php

namespace Impruthvi\CashierDunning\Entitlements\Exceptions;

use RuntimeException;

class InvalidResolver extends RuntimeException
{
    public static function returnedNonArray(string $type): self
    {
        return new self(
            "The entitlement resolver returned {$type}; it must return an array of ".
            "feature name => value, such as ['teams' => true, 'projects' => 10]."
        );
    }

    public static function returnedNonScalar(string $feature, string $type): self
    {
        return new self(
            "The entitlement resolver returned {$type} for feature [{$feature}]. ".
            'Entitlement values are written into fixtures and diffed across runs, '.
            'so they must be scalars or null.'
        );
    }

    public static function returnedNonStringKey(string $key): self
    {
        return new self(
            "The entitlement resolver returned the key [{$key}], which is not a ".
            'feature name. Snapshots are keyed by feature, not by position.'
        );
    }

    public static function notResolvable(string $class): self
    {
        return new self(
            "Configured entitlement resolver [{$class}] does not implement ".
            'Impruthvi\CashierDunning\Contracts\EntitlementResolver.'
        );
    }
}
