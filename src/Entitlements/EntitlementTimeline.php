<?php

namespace Impruthvi\CashierDunning\Entitlements;

use Impruthvi\CashierDunning\Contracts\EntitlementResolver;

/**
 * Walks a replay, asking the resolver once after every event and remembering
 * which event moved what.
 *
 * Per-event rather than per-step: a step delivering `invoice.payment_failed`
 * and `customer.subscription.updated` together changes entitlements at one of
 * the two, and only asking between them can say which.
 *
 * The resolver is called once at the start, before any event, so the first
 * change is measured against the app's actual starting position rather than
 * against nothing.
 */
final class EntitlementTimeline
{
    private Snapshot $current;

    /** @var list<EntitlementChange> */
    private array $changes = [];

    public function __construct(
        private readonly EntitlementResolver $resolver,
        private readonly object $billable,
    ) {
        $this->current = Snapshot::of($this->resolver->resolve($this->billable));
    }

    /**
     * Record the effect of one event having been applied.
     *
     * @param  array<string, mixed>  $event
     * @return list<EntitlementChange> what this event alone changed
     */
    public function observe(array $event): array
    {
        $next = Snapshot::of($this->resolver->resolve($this->billable));

        $changes = array_map(
            static fn (EntitlementChange $change): EntitlementChange => $change->causedBy($event),
            $this->current->diff($next)
        );

        $this->current = $next;
        $this->changes = [...$this->changes, ...$changes];

        return $changes;
    }

    public function snapshot(): Snapshot
    {
        return $this->current;
    }

    /** @return list<EntitlementChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Changes that took something away, in order. This is the list a dunning
     * scenario is actually about: an app that revokes access on the first
     * failed payment is losing customers Stripe would have recovered.
     *
     * @return list<EntitlementChange>
     */
    public function losses(): array
    {
        return array_values(array_filter(
            $this->changes,
            static fn (EntitlementChange $change): bool => $change->isLoss()
        ));
    }
}
