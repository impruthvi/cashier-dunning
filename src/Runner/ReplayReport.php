<?php

namespace Impruthvi\CashierDunning\Runner;

/**
 * The outcome of a replay.
 *
 * Counts assertions rather than only collecting failures. A run that delivered
 * no events and compared no entitlements has nothing to say, and reporting that
 * as success is the specific failure this package exists to prevent — a green
 * billing test that proves nothing is worse than no test, because it stops
 * anyone looking.
 */
final readonly class ReplayReport
{
    /**
     * @param  list<StepResult>  $steps
     * @param  array<string, int>  $sideEffects  what the application tried to do to the
     *                                           outside world, counted by kind
     * @param  int  $blockedDeliveries  how many of those attempts the replay refused to
     *                                  complete
     */
    public function __construct(
        public string $scenario,
        public array $steps,
        public int $assertions,
        public bool $completed,
        public array $sideEffects = [],
        public int $blockedDeliveries = 0,
    ) {}

    public function passed(): bool
    {
        return $this->completed
            && $this->assertions > 0
            && $this->failures() === [];
    }

    /** @return list<StepResult> */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (StepResult $step): bool => $step->failed()
        ));
    }

    /**
     * Why the run is not a pass, in the order the reasons matter.
     *
     * "No assertions ran" comes first because it invalidates everything else: a
     * run that asserted nothing has no findings, only an absence.
     *
     * A failure is reported before the fact that the run stopped, because the
     * stop is the consequence and the mismatch is the cause. Leading with
     * "replay stopped early" would hide the only useful sentence behind a
     * symptom.
     */
    public function verdict(): string
    {
        $failures = $this->failures();

        return match (true) {
            $this->assertions === 0 => 'No assertions ran. The fixture delivered no events and '.
                'declared no entitlements, so this run proved nothing.',
            $failures !== [] => count($failures).' step(s) did not match the recording, '.
                'starting at step '.$failures[0]->index.' ('.$failures[0]->label.').',
            ! $this->completed => 'Replay stopped early without a recorded failure.',
            default => 'All '.$this->assertions.' assertions passed.',
        };
    }

    public function eventsDelivered(): int
    {
        return array_sum(array_map(
            static fn (StepResult $step): int => count($step->events),
            $this->steps
        ));
    }
}
