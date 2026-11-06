<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

use Impruthvi\CashierDunning\Fixtures\ApiExchange;
use Impruthvi\CashierDunning\Fixtures\MatchPath;
use RuntimeException;

class InvalidFixture extends RuntimeException
{
    public static function missingKey(string $key): self
    {
        return new self("Fixture is missing required key [{$key}].");
    }

    public static function malformed(string $reason): self
    {
        return new self("Fixture is invalid: {$reason}");
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

    /**
     * A recording that ended early is plausible and wrong: it replays green
     * forever. So it is refused when saved or loaded rather than quietly replayed.
     *
     * @param  list<string>  $missing
     */
    public static function incomplete(string $scenario, array $missing): IncompleteFixture
    {
        return new IncompleteFixture(
            "Fixture for scenario [{$scenario}] is missing required events: ".implode(', ', $missing).'. '.
            'The recording ended before its manifest was satisfied; re-record it.',
            $missing,
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

    public static function literalProviderId(int $step, string $where, string $path, string $kind): self
    {
        return self::malformedStep(
            $step,
            "{$where} contains a literal provider id at [{$path}]. Use a {$kind} placeholder such as {{".$kind.'_1}}.'
        );
    }

    public static function unrecognisedPlaceholder(int $index, string $token): self
    {
        return self::malformedStep(
            $index,
            "[{$token}] is not a placeholder this version of cashier-dunning understands. ".
            'Expected a provider id such as {{sub_1}} or a point on the timeline such as {{t+14d}}.'
        );
    }

    /**
     * A match path that breaks {@see MatchPath}'s shape rule. Not a
     * DisallowedField: a path is not a field, and the allowlist does not judge it.
     */
    public static function malformedMatchPath(int $index, ApiExchange $exchange, string $problem): self
    {
        return self::malformedStep($index, "the match path of [{$exchange->method} {$exchange->path}] {$problem}");
    }

    /** A missing path reads as `''`, which can never match a request. */
    public static function missingMatchPath(int $index, ApiExchange $exchange): self
    {
        return self::malformedStep(
            $index,
            "the API exchange [{$exchange->method}] has no match path. ".
            'Every exchange matches on one, such as /v1/subscriptions/{{sub_1}}.'
        );
    }
}
