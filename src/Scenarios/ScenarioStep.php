<?php

namespace Impruthvi\CashierDunning\Scenarios;

use Impruthvi\CashierDunning\Fixtures\Duration;

/**
 * One point in a scenario: move the clock here, then do this.
 *
 * The mirror image of a fixture step. A fixture step says what happened; a
 * scenario step says what to make happen. Recording turns the second into the
 * first.
 */
final readonly class ScenarioStep
{
    public function __construct(
        public Duration $advanceTo,
        public string $label,
        public Action $action,
    ) {}

    public static function at(string $advanceTo, string $label, ?Action $action = null): self
    {
        return new self(Duration::parse($advanceTo), $label, $action ?? Action::wait());
    }
}
