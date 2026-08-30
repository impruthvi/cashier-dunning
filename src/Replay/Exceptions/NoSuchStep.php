<?php

namespace Impruthvi\CashierDunning\Replay\Exceptions;

use RuntimeException;

/**
 * Raised when the replay transport is pointed at a step the fixture does not
 * have. Always a bug in the runner, never in the fixture or the application, so
 * it fails immediately rather than degrading into "no recorded response".
 */
class NoSuchStep extends RuntimeException
{
    public static function at(int $index, int $available): self
    {
        return new self(
            "Replay was pointed at step {$index}, but the fixture has {$available} step(s)."
        );
    }
}
