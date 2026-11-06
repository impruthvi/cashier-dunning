<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\Exceptions\RecordingRefused;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Runner\StepResult;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Throwable;

/**
 * Records a scenario, replays it, and only then writes it.
 *
 * A fresh recording is saved only after a clean replay. Every refusal, at
 * whatever stage, is handed to Quarantine with the attempt it interrupted. No
 * caller decides between saving and quarantine. Saving replaces a previous
 * fixture only once the new file is complete.
 *
 * The replay does two jobs at once. A recording captures what Stripe did, but a
 * fixture's entitlement column is what the *application* made of it, and that
 * does not exist until the events have been through the application. Replaying
 * the fresh recording produces it honestly, rather than the recorder guessing at
 * a policy it cannot see.
 *
 * And a recording nobody has replayed is a recording nobody knows is usable. A
 * fixture that cannot be replayed is worse than no fixture: it looks like
 * coverage. So a failed replay is refused and quarantined like a short
 * recording. The capture cost real Stripe quota and the test clock is already
 * gone, so it is the most expensive thing to throw away.
 */
final readonly class RecordAndVerify
{
    /**
     * @param  string  $fixturePath  the application's fixture directory, the
     *                               same one replay reads from
     */
    public function __construct(
        private Recorder $recorder,
        private ReplayRunner $runner,
        private string $fixturePath,
    ) {}

    /**
     * @return array{fixture: Fixture, path: string} the fixture as written, and where
     *
     * @throws RecordingRefused when no fixture was written; its artifactPath
     *                          says where the capture was quarantined, or is null if writing failed
     */
    public function record(Scenario $scenario, ?CarbonImmutable $startsAt = null): array
    {
        // A round number in the recent past. Stripe rejects a clock frozen in
        // the future, and a scenario that starts mid-hour makes every recorded
        // offset harder to read than it needs to be.
        $attempt = new RecordingAttempt($scenario, $startsAt ?? CarbonImmutable::now('UTC')->subDay()->startOfHour());

        try {
            $recorded = $this->recorder->record($attempt);

            $report = $this->verified($recorded);
            $fixture = $recorded->withEntitlements(array_map(
                static fn (StepResult $step): array => $step->actualEntitlements,
                $report->steps
            ));

            $path = $this->fixturePath
                .DIRECTORY_SEPARATOR.$fixture->provider->value
                .DIRECTORY_SEPARATOR.$fixture->scenario.'.json';

            try {
                FixtureFile::write($fixture, $path);
            } catch (Throwable $cause) {
                throw Refusal::saveFailed($fixture, $report, $cause);
            }
        } catch (Refusal $refusal) {
            throw (new Quarantine($this->fixturePath))->keep($attempt, $refusal);
        }

        return ['fixture' => $fixture, 'path' => $path];
    }

    /**
     * The successful replay, including the entitlements to save at each step.
     *
     * @throws Refusal when the recording does not replay clean
     */
    private function verified(Fixture $recorded): ReplayReport
    {
        try {
            $report = $this->runner->run($recorded);
        } catch (SimulationFailed $e) {
            throw Refusal::replayRefused($recorded, $e);
        }

        if (! $report->passed()) {
            throw Refusal::didNotReplay($recorded, $report);
        }

        return $report;
    }
}
