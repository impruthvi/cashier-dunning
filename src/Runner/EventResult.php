<?php

namespace Impruthvi\CashierDunning\Runner;

use Impruthvi\CashierDunning\Entitlements\EntitlementChange;

/**
 * What happened when one recorded webhook was delivered to the application.
 */
final readonly class EventResult
{
    /** @param list<EntitlementChange> $changes */
    public function __construct(
        public string $id,
        public string $type,
        public int $status,
        public array $changes,
        public ?string $failure = null,
    ) {}

    public function failed(): bool
    {
        return $this->failure !== null;
    }
}
