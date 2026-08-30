<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

use RuntimeException;

/**
 * Raised when a fixture carries a field the allowlist does not permit.
 *
 * Fixtures are published to a public repository. The allowlist fails closed:
 * on write, unlisted fields are dropped; on validate, their presence is an
 * error, because a fixture containing them was not produced by this package's
 * writer and has not been through redaction.
 */
class DisallowedField extends RuntimeException
{
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
