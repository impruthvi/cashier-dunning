<?php

namespace Impruthvi\CashierDunning\Runner;

/**
 * What happened at one point in the replayed timeline.
 */
final readonly class StepResult
{
    /**
     * @param  list<EventResult>  $events
     * @param  array<string, scalar|null>  $expectedEntitlements
     * @param  array<string, scalar|null>  $actualEntitlements
     * @param  list<string>  $mismatches
     */
    public function __construct(
        public int $index,
        public string $label,
        public string $advanceTo,
        public array $events,
        public array $expectedEntitlements,
        public array $actualEntitlements,
        public array $mismatches,
        public ?string $failure = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure !== null
            || $this->mismatches !== []
            || array_filter($this->events, static fn (EventResult $e): bool => $e->failed()) !== [];
    }
}
