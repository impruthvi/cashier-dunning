<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

use RuntimeException;

class InvalidFixture extends RuntimeException
{
    public static function missingKey(string $key): self
    {
        return new self("Fixture is missing required key [{$key}].");
    }

    public static function unknownProvider(string $provider): self
    {
        return new self(
            "Fixture declares provider [{$provider}], which this version of ".
            'cashier-dunning cannot replay.'
        );
    }

    public static function unsupportedFormatVersion(int $found, int $supported): self
    {
        return new self(
            "Fixture uses format version {$found}; this version of cashier-dunning ".
            "supports version {$supported}. Upgrade the package or re-record the fixture."
        );
    }

    /**
     * A fixture with no steps asserts nothing but still reports success. That is
     * the exact failure this package exists to prevent, so it is refused at load.
     */
    public static function empty(string $scenario): self
    {
        return new self(
            "Fixture for scenario [{$scenario}] contains no steps. A fixture with ".
            'no steps would replay green while asserting nothing.'
        );
    }

    public static function malformedDuration(string $value): self
    {
        return new self(
            "Cannot parse duration [{$value}]. Expected a value like '21d', '21d1h' or '2h30m'."
        );
    }

    public static function malformedStep(int $index, string $reason): self
    {
        return new self("Step {$index} is invalid: {$reason}");
    }
}
