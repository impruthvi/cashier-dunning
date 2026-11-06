<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Scenarios\Scenario;

/**
 * One try at recording a scenario, and what Stripe sent back during it.
 *
 * RecordAndVerify starts it and the Recorder fills it as recording goes on, so
 * whatever stage refuses the recording, what was captured up to that moment
 * is already here for the quarantine. Nothing has to be handed back up
 * through an exception to survive a refusal.
 *
 * @internal
 */
final class RecordingAttempt
{
    /** @var list<array<string, mixed>> every event Stripe emitted, as it arrived */
    public array $captured = [];

    /** @var list<string> the request id of every Stripe response, clock deletion included */
    public array $requestIds = [];

    public function __construct(
        public readonly Scenario $scenario,
        public readonly CarbonImmutable $startedAt,
    ) {}
}
