<?php

namespace Impruthvi\CashierDunning\Record;

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Record\Exceptions\RecordingRefused;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Runner\StepResult;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;

/**
 * Records a scenario, replays it, and only then writes it.
 *
 * Every fresh recording ends here in one of two ways: as a fixture on disk that
 * has replayed clean once, or as a quarantine beside it with a refusal saying
 * why. No caller decides between the two, so no caller can get it wrong.
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
     *                          says where the capture was quarantined
     */
    public function record(Scenario $scenario, ?CarbonImmutable $startsAt = null): array
    {
        // A round number in the recent past. Stripe rejects a clock frozen in
        // the future, and a scenario that starts mid-hour makes every recorded
        // offset harder to read than it needs to be.
        $startsAt ??= CarbonImmutable::now('UTC')->subDay()->startOfHour();

        try {
            ['fixture' => $recorded, 'captured' => $captured] = $this->recorder->record($scenario, $startsAt);

            $fixture = $this->verified($recorded, $captured, $startsAt);
        } catch (RecordingRefused $refusal) {
            throw $refusal->quarantinedAt($this->quarantine($scenario, $refusal));
        }

        $path = $this->fixturePath
            .DIRECTORY_SEPARATOR.$fixture->provider->value
            .DIRECTORY_SEPARATOR.$fixture->scenario.'.json';

        FixtureFile::write($fixture, $path);

        return ['fixture' => $fixture, 'path' => $path];
    }

    /**
     * The recording with the entitlements the application granted at each step.
     *
     * @param  list<array<string, mixed>>  $captured  what Stripe sent, kept for the quarantine
     *
     * @throws RecordingRefused when the recording does not replay clean
     */
    private function verified(Fixture $recorded, array $captured, CarbonImmutable $startsAt): Fixture
    {
        try {
            $report = $this->runner->run($recorded);
        } catch (SimulationFailed $e) {
            throw RecordingRefused::replayRefused($recorded, $captured, $startsAt, $e);
        }

        if (! $report->passed()) {
            throw RecordingRefused::didNotReplay($recorded, $captured, $startsAt, $report);
        }

        return $recorded->withEntitlements(array_map(
            static fn (StepResult $step): array => $step->actualEntitlements,
            $report->steps
        ));
    }

    /** @return string where the quarantine was written */
    private function quarantine(Scenario $scenario, RecordingRefused $refusal): string
    {
        return (new QuarantineArtifact(
            scenario: $scenario,
            captured: $refusal->captured,
            // Not collected yet: https://github.com/impruthvi/cashier-dunning/issues/50
            requestIds: [],
            reason: $refusal->reason,
            startedAt: $refusal->startedAt,
            failedAt: CarbonImmutable::now('UTC'),
            replay: $refusal->report,
            fixture: $refusal->fixture,
        ))->write($this->fixturePath.DIRECTORY_SEPARATOR.'quarantine');
    }
}
