<?php

namespace Impruthvi\CashierDunning\Simulation;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Duration;

/**
 * The handle a running simulation gets: who is being billed, with what
 * credentials, and where in the timeline we are.
 *
 * Handed to the callback rather than reachable statically, so nothing can hold
 * onto it after the environment has torn its global state back down.
 */
final class SimulationContext
{
    private Duration $position;

    public function __construct(
        public readonly object $billable,
        public readonly EphemeralCredentials $credentials,
        public readonly CarbonImmutable $startedAt,
    ) {
        $this->position = Duration::fromMinutes(0);
    }

    /**
     * Move the application's clock to a point in the timeline.
     *
     * Both Carbon classes are set: Laravel code reads whichever it prefers, and
     * a fixture that advanced only one of them would leave `now()` disagreeing
     * with itself halfway through a dunning window.
     */
    public function advanceTo(Duration $offset): CarbonImmutable
    {
        $this->position = $offset;

        $moment = $this->startedAt->addMinutes($offset->minutes);

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        return $moment;
    }

    public function position(): Duration
    {
        return $this->position;
    }

    public function now(): CarbonImmutable
    {
        return $this->startedAt->addMinutes($this->position->minutes);
    }
}
