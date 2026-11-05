<?php

namespace Impruthvi\CashierDunning\Record\Exceptions;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Record\ClockAdvanceFailed;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use RuntimeException;
use Stripe\Exception\RateLimitException;
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
 */
final class RecordingRefused extends RuntimeException
{
    /**
     * @param  list<string>  $missing
     * @param  list<array<string, mixed>>  $captured
     */
    private function __construct(
        public readonly string $scenario,
        public readonly string $reason,
        private readonly string $refusal,
        private readonly ?string $consequence,
        public readonly array $missing,
        public readonly array $captured,
        public readonly CarbonImmutable $startedAt,
        public readonly ?ReplayReport $report = null,
        public readonly ?Fixture $fixture = null,
        public readonly ?string $artifactPath = null,
        ?Throwable $previous = null,
        public readonly ?Throwable $quarantineFailure = null,
    ) {
        parent::__construct($this->compose(), previous: $previous);
    }

    /**
     * @param  list<string>  $missing
     * @param  list<array<string, mixed>>  $captured
     */
    public static function missingEvents(string $scenario, array $missing, array $captured, CarbonImmutable $startedAt): self
    {
        return self::incomplete($scenario, 'the recording ended before every required event arrived', $captured, $startedAt, $missing);
    }

    /** @param  list<array<string, mixed>>  $captured */
    public static function rateLimited(string $scenario, array $captured, CarbonImmutable $startedAt, RateLimitException $cause): self
    {
        return self::incomplete($scenario, 'Stripe rate limited the recording: '.$cause->getMessage(), $captured, $startedAt, previous: $cause);
    }

    /** @param  list<array<string, mixed>>  $captured */
    public static function clockFailed(string $scenario, array $captured, CarbonImmutable $startedAt, ClockAdvanceFailed $cause): self
    {
        return self::incomplete($scenario, 'the test clock did not finish advancing: '.$cause->getMessage(), $captured, $startedAt, previous: $cause);
    }

    /** @param  list<array<string, mixed>>  $captured */
    public static function invalidFixture(string $scenario, array $captured, CarbonImmutable $startedAt, InvalidFixture $cause): self
    {
        return new self(
            scenario: $scenario,
            reason: $cause->getMessage(),
            refusal: 'could not be turned into a fixture',
            consequence: null,
            missing: [],
            captured: $captured,
            startedAt: $startedAt,
            previous: $cause,
        );
    }

    /**
     * The replay ran, and the application did not get through it.
     *
     * @param  list<array<string, mixed>>  $captured  what Stripe sent, not the fixture's normalised copy
     */
    public static function didNotReplay(Fixture $fixture, array $captured, CarbonImmutable $startedAt, ReplayReport $report): self
    {
        return self::unreplayable($fixture, $captured, $startedAt, $report->verdict(), report: $report);
    }

    /**
     * The simulation environment refused to start or finish the replay.
     *
     * @param  list<array<string, mixed>>  $captured  what Stripe sent, not the fixture's normalised copy
     */
    public static function replayRefused(Fixture $fixture, array $captured, CarbonImmutable $startedAt, SimulationFailed $cause): self
    {
        return self::unreplayable($fixture, $captured, $startedAt, $cause->getMessage(), previous: $cause);
    }

    /** @param list<array<string, mixed>> $captured */
    public static function saveFailed(Fixture $fixture, array $captured, CarbonImmutable $startedAt, ReplayReport $report, Throwable $cause): self
    {
        return new self(
            scenario: $fixture->scenario,
            reason: $cause->getMessage(),
            refusal: 'could not be saved',
            consequence: 'Any previous fixture was left untouched.',
            missing: [],
            captured: $captured,
            startedAt: $startedAt,
            report: $report,
            fixture: $fixture,
            previous: $cause,
        );
    }

    /** Retain the original cause as well as the failure to save diagnostics. */
    public function quarantineFailed(Throwable $cause): self
    {
        return $this->copy(quarantineFailure: $cause);
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

    /** The same refusal, now that what it captured has been quarantined at this path. */
    public function quarantinedAt(string $artifactPath): self
    {
        return $this->copy(artifactPath: $artifactPath);
    }

    /**
     * The same refusal with the quarantine's outcome: where it was written, or
     * why it could not be. The message is composed again, so it reports that outcome.
     */
    private function copy(?string $artifactPath = null, ?Throwable $quarantineFailure = null): self
    {
        return new self(
            scenario: $this->scenario,
            reason: $this->reason,
            refusal: $this->refusal,
            consequence: $this->consequence,
            missing: $this->missing,
            captured: $this->captured,
            startedAt: $this->startedAt,
            report: $this->report,
            fixture: $this->fixture,
            artifactPath: $artifactPath,
            previous: $this->getPrevious(),
            quarantineFailure: $quarantineFailure,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $captured
     * @param  list<string>  $missing
     */
    private static function incomplete(
        string $scenario,
        string $reason,
        array $captured,
        CarbonImmutable $startedAt,
        array $missing = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            scenario: $scenario,
            reason: $reason,
            refusal: 'is incomplete',
            consequence: 'A short recording replays green while proving nothing, and nothing downstream can tell the difference.',
            missing: $missing,
            captured: $captured,
            startedAt: $startedAt,
            previous: $previous,
        );
    }

    /** @param  list<array<string, mixed>>  $captured */
    private static function unreplayable(
        Fixture $fixture,
        array $captured,
        CarbonImmutable $startedAt,
        string $reason,
        ?ReplayReport $report = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            scenario: $fixture->scenario,
            reason: $reason,
            refusal: 'did not replay clean',
            consequence: 'A recording that cannot be replayed is worse than no recording: it looks like coverage.',
            missing: [],
            captured: $captured,
            startedAt: $startedAt,
            report: $report,
            fixture: $fixture,
            previous: $previous,
        );
    }

    private function compose(): string
    {
        $lines = [
            "Recording [{$this->scenario}] {$this->refusal}: ".rtrim($this->reason, '.').'.',
            '',
            trim('No fixture was written. '.$this->consequence),
        ];

        if ($this->missing !== []) {
            $lines[] = '';
            $lines[] = 'Never arrived: '.implode(', ', $this->missing);
        }

        $types = array_values(array_unique(array_map(
            static fn (array $event): string => (string) ($event['type'] ?? 'unknown'),
            $this->captured
        )));

        $lines[] = '';
        $lines[] = $types === []
            ? 'Nothing was captured at all.'
            : 'Captured '.count($this->captured).' event(s): '.implode(', ', $types);

        if ($this->artifactPath !== null) {
            $lines[] = '';
            $lines[] = "Diagnostics written to {$this->artifactPath}";
        }

        if ($this->quarantineFailure !== null) {
            $lines[] = '';
            $lines[] = 'Quarantine could not be saved: '.$this->quarantineFailure->getMessage();
            $lines[] = 'No diagnostic artifact was written.';
        }

        return implode("\n", $lines);
    }
}
