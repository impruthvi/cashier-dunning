<?php

namespace Impruthvi\CashierDunning\Chaos;

use Impruthvi\CashierDunning\Runner\ReplayReport;

/**
 * One run of the timeline under one ordering.
 */
final readonly class PassResult
{
    /**
     * @param  array<string, int>  $sideEffects
     * @param  array<string, scalar|null>  $finalEntitlements
     * @param  list<string>  $divergences
     */
    public function __construct(
        public int $pass,
        public string $ordering,
        public ReplayReport $report,
        public array $sideEffects,
        public array $finalEntitlements,
        public array $divergences = [],
        public bool $notApplicable = false,
    ) {}

    public function passed(): bool
    {
        return $this->notApplicable || ($this->report->passed() && $this->divergences === []);
    }
}
