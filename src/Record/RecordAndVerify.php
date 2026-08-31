<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Runner\StepResult;
use Impruthvi\CashierDunning\Scenarios\Scenario;

/**
 * Records a scenario, then immediately replays it before writing anything.
 *
 * Two problems solved by one pass.
 *
 * A recording captures what Stripe did, but a fixture's entitlement column is
 * what the *application* made of it, and that does not exist until the events
 * have been through the application. Replaying the fresh recording produces it
 * honestly, rather than the recorder guessing at a policy it cannot see.
 *
 * And a recording nobody has replayed is a recording nobody knows is usable. A
 * fixture that cannot be replayed is worse than no fixture: it looks like
 * coverage. Verifying before writing means a file on disk is one that has
 * already worked at least once.
 */
final readonly class RecordAndVerify
{
    public function __construct(
        private Recorder $recorder,
        private ReplayRunner $runner,
    ) {}

    /**
     * @return array{fixture: Fixture, report: ReplayReport}
     */
    public function record(Scenario $scenario, ?CarbonImmutable $startsAt = null): array
    {
        $recorded = $this->recorder->record($scenario, $startsAt);

        $report = $this->runner->run($recorded);

        return [
            'fixture' => $recorded->withEntitlements(array_map(
                static fn (StepResult $step): array => $step->actualEntitlements,
                $report->steps
            )),
            'report' => $report,
        ];
    }
}
