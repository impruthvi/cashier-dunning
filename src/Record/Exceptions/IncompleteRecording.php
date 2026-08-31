<?php

namespace Impruthvi\CashierDunning\Record\Exceptions;

use RuntimeException;

/**
 * Raised when a recording did not capture everything its scenario promised.
 *
 * The alternative is worse than a failed recording: a fixture that is short but
 * plausible replays green forever, and the drift job compares it against
 * another short recording and agrees. Nothing downstream can catch it, because
 * everything downstream is reading the same broken file.
 *
 * So an incomplete recording never becomes a fixture. It becomes a quarantined
 * artifact, written somewhere the loader will not look.
 */
class IncompleteRecording extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     * @param  list<string>  $captured
     */
    public function __construct(
        public readonly string $scenario,
        public readonly array $missing,
        public readonly array $captured,
        public readonly string $reason,
        public readonly ?string $artifactPath = null,
    ) {
        parent::__construct($this->compose());
    }

    /** @param list<string> $captured */
    public static function missingEvents(string $scenario, array $missing, array $captured, ?string $artifactPath = null): self
    {
        return new self(
            scenario: $scenario,
            missing: $missing,
            captured: $captured,
            reason: 'the recording ended before every required event arrived',
            artifactPath: $artifactPath,
        );
    }

    /** @param list<string> $captured */
    public static function rateLimited(string $scenario, array $captured, string $detail, ?string $artifactPath = null): self
    {
        return new self(
            scenario: $scenario,
            missing: [],
            captured: $captured,
            reason: 'Stripe rate limited the recording: '.$detail,
            artifactPath: $artifactPath,
        );
    }

    /** @param list<string> $captured */
    public static function clockFailed(string $scenario, array $captured, string $detail, ?string $artifactPath = null): self
    {
        return new self(
            scenario: $scenario,
            missing: [],
            captured: $captured,
            reason: 'the test clock did not finish advancing: '.$detail,
            artifactPath: $artifactPath,
        );
    }

    private function compose(): string
    {
        $lines = [
            "Recording [{$this->scenario}] is incomplete: {$this->reason}.",
            '',
            'No fixture was written. A short recording replays green while '.
            'proving nothing, and nothing downstream can tell the difference.',
        ];

        if ($this->missing !== []) {
            $lines[] = '';
            $lines[] = 'Never arrived: '.implode(', ', $this->missing);
        }

        $lines[] = '';
        $lines[] = $this->captured === []
            ? 'Nothing was captured at all.'
            : 'Captured '.count($this->captured).' event(s): '.implode(', ', array_unique($this->captured));

        if ($this->artifactPath !== null) {
            $lines[] = '';
            $lines[] = "Diagnostics written to {$this->artifactPath}";
        }

        return implode("\n", $lines);
    }
}
