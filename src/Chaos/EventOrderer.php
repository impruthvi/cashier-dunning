<?php

namespace Impruthvi\CashierDunning\Chaos;

/**
 * Rearranges a step's events to model what Stripe actually promises.
 *
 * Stripe guarantees at-least-once delivery and says plainly that it does not
 * guarantee order — "a subscription might be deleted before the corresponding
 * creation event arrives". Almost no application is tested against that,
 * because live Stripe cannot be asked to misbehave on demand: you cannot tell it
 * to deliver today's events backwards, or to deliver one twice.
 *
 * A fixture is a list you control, so replay can. This is the one thing replay
 * does that a live integration test cannot do at all.
 *
 * Orderings are derived from a seed rather than from `shuffle()`, so a failing
 * pass can be reproduced exactly — including on another machine and another PHP
 * version. A chaos failure nobody can reproduce is a chaos failure nobody fixes.
 */
final readonly class EventOrderer
{
    public function __construct(
        public bool $shuffle = false,
        public bool $duplicate = false,
        public int $seed = 0,
    ) {}

    public static function inOrder(): self
    {
        return new self;
    }

    public function isChaotic(): bool
    {
        return $this->shuffle || $this->duplicate;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function apply(array $events, int $stepIndex, int $pass): array
    {
        if ($this->shuffle && count($events) > 1) {
            $events = $this->reorder($events, $stepIndex, $pass);
        }

        if ($this->duplicate) {
            // Appended rather than interleaved. Stripe's redelivery arrives
            // after the original, sometimes much later, and an application that
            // survives a duplicate arriving immediately can still fail one that
            // arrives after the state has moved on.
            $events = [...$events, ...$events];
        }

        return $events;
    }

    public function describe(): string
    {
        return match (true) {
            $this->shuffle && $this->duplicate => 'shuffled and duplicated',
            $this->shuffle => 'shuffled',
            $this->duplicate => 'duplicated',
            default => 'in order',
        };
    }

    /**
     * Fisher-Yates over a small deterministic generator.
     *
     * PHP's own shuffle() is seedable but not stable across versions, and a
     * seed that reproduces a failure on one machine and not another is worse
     * than no seed: it invites people to believe the bug went away.
     *
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function reorder(array $events, int $stepIndex, int $pass): array
    {
        $state = ($this->seed * 2654435761) ^ ($stepIndex * 40503) ^ ($pass * 97) ^ 0x9E3779B9;

        for ($i = count($events) - 1; $i > 0; $i--) {
            $state = $this->next($state);
            $j = $state % ($i + 1);

            [$events[$i], $events[$j]] = [$events[$j], $events[$i]];
        }

        return $events;
    }

    private function next(int $state): int
    {
        // xorshift, masked to 32 bits so the sequence is identical on 32- and
        // 64-bit builds.
        $state ^= ($state << 13) & 0xFFFFFFFF;
        $state ^= ($state >> 17);
        $state ^= ($state << 5) & 0xFFFFFFFF;

        return abs($state & 0xFFFFFFFF);
    }
}
