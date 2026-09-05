<?php

namespace Impruthvi\CashierDunning\Chaos;

use Impruthvi\CashierDunning\Guards\OutboundGuard;

/**
 * Records what an application tried to do to the outside world during a replay.
 *
 * Idempotency bugs hide in final state. An application that emails a customer
 * twice, or dispatches a dunning job twice, ends the run with a perfectly
 * correct subscription row — the damage is in what left the building, and the
 * database has no memory of it. Comparing end states across orderings would
 * therefore find nothing, which is exactly why most billing test suites believe
 * their handlers are idempotent.
 *
 * The ledger only writes down what it is told. Observation and interception both
 * live in {@see OutboundGuard}, so one place decides which effects a replay is
 * allowed to complete.
 */
final class SideEffectLedger
{
    /** @var list<string> */
    private array $entries = [];

    private int $blocked = 0;

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * An effect the application attempted and the replay refused to complete.
     *
     * Counted as well as recorded. The signature says the application tried to
     * mail the customer twice; this says nobody was actually mailed, which is
     * the sentence a developer running the command on a laptop with live SMTP
     * credentials needs to read.
     */
    public function recordBlocked(string $entry): void
    {
        $this->entries[] = $entry;
        $this->blocked++;
    }

    /** @return list<string> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function blocked(): int
    {
        return $this->blocked;
    }

    /**
     * What the application did, counted by kind.
     *
     * Order is deliberately discarded and counts are kept. Two orderings are
     * allowed to produce the same effects in a different sequence — that is
     * what "order does not matter" means — but they are not allowed to produce
     * a different number of them, because that is a customer emailed twice.
     *
     * @return array<string, int>
     */
    public function signature(): array
    {
        $counts = array_count_values($this->entries);

        ksort($counts);

        return $counts;
    }
}
