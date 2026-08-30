<?php

namespace Impruthvi\CashierDunning\Entitlements;

/**
 * What a billable was entitled to at one point in a timeline.
 *
 * Keys are sorted on construction. A resolver that happens to build its array
 * in a different order between runs would otherwise re-record a fixture that
 * differs from the last one without anything having changed.
 */
final readonly class Snapshot
{
    /** @param array<string, scalar|null> $features */
    private function __construct(public array $features) {}

    /** @param array<string, scalar|null> $features */
    public static function of(array $features): self
    {
        ksort($features);

        return new self($features);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function get(string $feature): string|int|float|bool|null
    {
        return $this->features[$feature] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->features === [];
    }

    /**
     * Changes needed to get from this snapshot to $next.
     *
     * A feature appearing or disappearing counts as a change against null, so a
     * resolver that stops reporting a feature is not silently ignored.
     *
     * @return list<EntitlementChange>
     */
    public function diff(self $next): array
    {
        $features = array_keys($this->features + $next->features);
        sort($features);

        $changes = [];

        foreach ($features as $feature) {
            $from = $this->get($feature);
            $to = $next->get($feature);

            if ($from !== $to) {
                $changes[] = new EntitlementChange($feature, $from, $to);
            }
        }

        return $changes;
    }

    public function equals(self $other): bool
    {
        return $this->features === $other->features;
    }

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return $this->features;
    }
}
