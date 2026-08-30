<?php

namespace Impruthvi\CashierDunning\Replay\Exceptions;

use RuntimeException;

/**
 * Raised when the application makes a Stripe call the fixture cannot answer.
 *
 * Never a default empty response. A replay that invents `{}` for calls it does
 * not recognise reports success while proving nothing, and the application
 * under test quietly branches on data that was never recorded.
 */
class UnmatchedRequest extends RuntimeException
{
    /** @param list<string> $available */
    public static function notRecorded(string $method, string $path, int $step, string $label, array $available): self
    {
        $offers = $available === []
            ? 'That step recorded no API calls at all.'
            : 'That step can answer: '.implode(', ', $available).'.';

        return new self(
            "The application called {$method} {$path} at step {$step} ({$label}), ".
            "which the fixture does not have a response for.\n\n{$offers}\n\n".
            'Either the application changed what it asks Stripe, or the fixture '.
            'needs re-recording.'
        );
    }

    public static function recordedInAnotherStep(
        string $method,
        string $path,
        int $step,
        string $label,
        int $recordedAt,
        string $recordedLabel,
    ): self {
        // Serving it anyway is the tempting bug. A subscription that was
        // `trialing` at step 1 is `past_due` at step 3, and answering step 3
        // with step 1's response would assert something that was true once and
        // is false now — while the run stays green.
        $direction = $recordedAt > $step ? 'later' : 'earlier';

        return new self(
            "The application called {$method} {$path} at step {$step} ({$label}). ".
            "The fixture has a response for that call, but at a {$direction} point ".
            "in the timeline: step {$recordedAt} ({$recordedLabel}).\n\n".
            'Serving it here would answer with state that was not true yet, or is '.
            'no longer true, and the run would still pass. Re-record the scenario '.
            'if the application legitimately makes this call at this point.'
        );
    }

    public static function fileUpload(string $path): self
    {
        return new self(
            "The application attempted a file upload to {$path} during replay. ".
            'Fixtures record billing lifecycles, not uploads.'
        );
    }
}
