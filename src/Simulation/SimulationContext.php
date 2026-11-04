<?php

namespace Impruthvi\CashierDunning\Simulation;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Chaos\SideEffectLedger;
use Impruthvi\CashierDunning\Fixtures\Duration;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Replay\Placeholders;

/**
 * The handle a running simulation gets: who is being billed, with what
 * credentials, and where in the timeline we are.
 *
 * Handed to the callback rather than reachable statically, so nothing can hold
 * onto it after the environment has torn its global state back down.
 *
 * @internal
 */
final class SimulationContext
{
    private Duration $position;

    public function __construct(
        public readonly object $billable,
        public readonly EphemeralCredentials $credentials,
        public readonly CarbonImmutable $startedAt,
        private readonly Fixture $fixture,
        private readonly FixtureHttpClient $client,
        private readonly Placeholders $placeholders,
        private readonly SideEffectLedger $ledger,
    ) {
        $this->advanceTo(Duration::fromMinutes(0));
    }

    public function enterStep(int $index): void
    {
        $this->client->atStep($index);
        $this->advanceTo($this->fixture->steps[$index]->advanceTo);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function resolvePlaceholders(array $payload): array
    {
        return $this->placeholders->resolve($payload);
    }

    /**
     * Move the application's clock to a point in the timeline.
     *
     * Both Carbon classes are set: Laravel code reads whichever it prefers, and
     * a fixture that advanced only one of them would leave `now()` disagreeing
     * with itself halfway through a dunning window.
     */
    private function advanceTo(Duration $offset): CarbonImmutable
    {
        $this->position = $offset;

        $moment = $this->startedAt->addMinutes($offset->minutes);

        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);

        return $moment;
    }

    /**
     * What the application has tried to do to the outside world so far this
     * run, counted by kind. See {@see SideEffectLedger::signature()}.
     *
     * @return array<string, int>
     */
    public function sideEffects(): array
    {
        return $this->ledger->signature();
    }

    /**
     * How many of those side effects had their delivery refused.
     */
    public function blockedDeliveries(): int
    {
        return $this->ledger->blocked();
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
