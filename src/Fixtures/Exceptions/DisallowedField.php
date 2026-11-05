<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

use Impruthvi\CashierDunning\Fixtures\ApiExchange;

/**
 * Raised when a fixture carries a field the allowlist does not permit.
 *
 * Fixtures are published to a public repository. The allowlist fails closed:
 * on write, unlisted fields are dropped; on load, their presence refuses the
 * fixture, because a fixture containing them was not produced by this
 * package's writer and has not been through redaction.
 */
class DisallowedField extends InvalidFixture
{
    /** @param  list<string>  $paths */
    public static function inStepEvent(int $step, string $eventType, array $paths): self
    {
        return self::notPermitted($step, "event [{$eventType}]", $paths);
    }

    /** @param  list<string>  $paths */
    public static function inApiResponse(int $step, ApiExchange $exchange, array $paths): self
    {
        return self::notPermitted($step, "the recorded response to [{$exchange->method} {$exchange->path}]", $paths);
    }

    /**
     * A hand author needs to know which half of the exchange to fix, so this
     * names the match body rather than the response.
     *
     * @param  list<string>  $parameters
     */
    public static function inApiMatch(int $step, ApiExchange $exchange, array $parameters): self
    {
        return new self(
            "Step {$step} is invalid: the match body of [{$exchange->method} {$exchange->path}] contains request parameters not permitted by the allowlist: ".implode(', ', $parameters).'. '.
            'Match on a permitted parameter instead, or add the parameter to the allowlist if it is safe to publish.'
        );
    }

    /** @param  list<string>  $paths */
    private static function notPermitted(int $step, string $where, array $paths): self
    {
        return new self(
            "Step {$step} is invalid: {$where} contains fields not permitted by the allowlist: ".implode(', ', $paths).'. '.
            'Re-record the fixture, or add the field to the allowlist if it is safe to publish.'
        );
    }
}
