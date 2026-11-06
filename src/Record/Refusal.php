<?php

namespace Impruthvi\CashierDunning\Record;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use RuntimeException;
use Stripe\Exception\RateLimitException;
use Throwable;

/**
 * Why a recording stage refused to go on, before anything is quarantined.
 *
 * Thrown by the Recorder and by RecordAndVerify, and caught only by
 * RecordAndVerify, which hands it to Quarantine together with the attempt.
 * What was captured lives on the attempt, not here, so a refusal only says
 * what went wrong and, past the recording itself, what fixture and replay it
 * got as far as.
 *
 * @internal
 */
final class Refusal extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        public readonly string $outcome,
        public readonly ?string $consequence,
        public readonly ?Fixture $fixture = null,
        public readonly ?ReplayReport $report = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, previous: $previous);
    }

    public static function missingEvents(): self
    {
        return self::incomplete('the recording ended before every required event arrived');
    }

    public static function rateLimited(RateLimitException $cause): self
    {
        return self::incomplete('Stripe rate limited the recording: '.$cause->getMessage(), $cause);
    }

    public static function clockFailed(ClockAdvanceFailed $cause): self
    {
        return self::incomplete('the test clock did not finish advancing: '.$cause->getMessage(), $cause);
    }

    public static function invalidFixture(InvalidFixture $cause): self
    {
        return new self(
            reason: $cause->getMessage(),
            outcome: 'could not be turned into a fixture',
            consequence: null,
            previous: $cause,
        );
    }

    /** The replay ran, and the application did not get through it. */
    public static function didNotReplay(Fixture $fixture, ReplayReport $report): self
    {
        return self::unreplayable($fixture, $report->verdict(), report: $report);
    }

    /** The simulation environment refused to start or finish the replay. */
    public static function replayRefused(Fixture $fixture, SimulationFailed $cause): self
    {
        return self::unreplayable($fixture, $cause->getMessage(), previous: $cause);
    }

    public static function saveFailed(Fixture $fixture, ReplayReport $report, Throwable $cause): self
    {
        return new self(
            reason: $cause->getMessage(),
            outcome: 'could not be saved',
            consequence: 'Any previous fixture was left untouched.',
            fixture: $fixture,
            report: $report,
            previous: $cause,
        );
    }

    private static function incomplete(string $reason, ?Throwable $previous = null): self
    {
        return new self(
            reason: $reason,
            outcome: 'is incomplete',
            consequence: 'A short recording replays green while proving nothing, and nothing downstream can tell the difference.',
            previous: $previous,
        );
    }

    private static function unreplayable(Fixture $fixture, string $reason, ?ReplayReport $report = null, ?Throwable $previous = null): self
    {
        return new self(
            reason: $reason,
            outcome: 'did not replay clean',
            consequence: 'A recording that cannot be replayed is worse than no recording: it looks like coverage.',
            fixture: $fixture,
            report: $report,
            previous: $previous,
        );
    }
}
