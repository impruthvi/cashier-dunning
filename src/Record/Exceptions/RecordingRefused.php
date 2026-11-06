<?php

namespace Impruthvi\CashierDunning\Record\Exceptions;

use Impruthvi\CashierDunning\Runner\ReplayReport;
use RuntimeException;
use Throwable;

/**
 * Raised when a recording does not become a fixture.
 *
 * Four ways that happens. The recording is incomplete: a required event never
 * arrived, Stripe rate limited it, or the test clock stuck. It cannot be
 * turned into a fixture. It does not replay clean through the application. Or
 * it replays clean but the fixture cannot be saved to disk.
 *
 * Each one is refused the same way, because each one is worse as a fixture than
 * no fixture at all. A short recording replays green while proving nothing, and
 * a recording that cannot be replayed looks like coverage. What was captured is
 * quarantined instead when persistence permits; otherwise the refusal reports
 * both failures without claiming an artifact.
 *
 * Only ever raised once the quarantine has been attempted, so the message is
 * the whole story: why, what never arrived, what was captured, and where the
 * diagnostics went or why they could not be written.
 */
final class RecordingRefused extends RuntimeException
{
    /**
     * @internal built by Quarantine, the only place a refusal is finished
     *
     * @param  ?ReplayReport  $report  the replay, when the recording got far enough to have one
     * @param  ?string  $artifactPath  where the quarantine was written, or null if it could not be
     */
    public function __construct(
        string $message,
        ?Throwable $previous,
        public readonly ?ReplayReport $report,
        public readonly ?string $artifactPath,
        private readonly ?Throwable $quarantineFailure = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /**
     * Log context for report(). The previous chain already holds the refusal's
     * own cause, so a failed quarantine is handed to the log here instead.
     *
     * @return array{quarantine_failure?: Throwable}
     */
    public function context(): array
    {
        return $this->quarantineFailure === null ? [] : ['quarantine_failure' => $this->quarantineFailure];
    }
}
