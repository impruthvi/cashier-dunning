<?php

namespace Impruthvi\CashierDunning\Fixtures\Exceptions;

/**
 * Raised when a fixture is missing a required event from its manifest.
 *
 * Its own type because the recorder refuses an incomplete recording
 * differently from one that is merely malformed: it names what never arrived.
 */
final class IncompleteFixture extends InvalidFixture
{
    /** @param  list<string>  $missing */
    public function __construct(string $message, public readonly array $missing)
    {
        parent::__construct($message);
    }
}
