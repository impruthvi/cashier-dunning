<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

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
        $list = implode(', ', $paths);

        return new self(
            "Step {$step} is invalid: event [{$eventType}] contains fields not permitted by the allowlist: {$list}. ".
            'Re-record the fixture, or add the field to the allowlist if it is safe to publish.'
        );
    }

    /** @param  list<string>  $paths */
    public static function inApiResponse(int $step, string $method, string $path, array $paths): self
    {
        $list = implode(', ', $paths);

        return new self(
            "Step {$step} is invalid: the recorded response to [{$method} {$path}] contains fields not permitted by the allowlist: {$list}. ".
            'Re-record the fixture, or add the field to the allowlist if it is safe to publish.'
        );
    }

    /** @param  list<string>  $paths */
    public static function inEvent(string $eventType, array $paths): self
    {
        $list = implode(', ', $paths);

        return new self(
            "Event [{$eventType}] contains fields not permitted by the allowlist: {$list}. ".
            'Re-record the fixture, or add the field to the allowlist if it is safe to publish.'
        );
    }
}
