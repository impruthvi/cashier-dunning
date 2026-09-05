<?php

namespace Impruthvi\CashierDunning\Chaos;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Runner\StepResult;

/**
 * Replays the same timeline several ways and compares what the application did.
 *
 * The baseline is the recording in the order Stripe happened to deliver it.
 * Every other pass is the same events under an order Stripe is equally entitled
 * to use, because it guarantees at-least-once delivery and no ordering at all.
 * "Equally entitled" is bounded by what EventOrderer can produce: permutation
 * within one step, and redelivery. Not reordering across a clock advance.
 *
 * A pass fails if the application ends up entitled to something different, or
 * if it did a different number of things to the outside world. The second half
 * is the one that finds real bugs: an application that emails a customer twice
 * still ends with a correct subscription row, so comparing end state alone
 * would call it idempotent.
 */
final readonly class ChaosRunner
{
    public function __construct(
        private ReplayRunner $runner,
        private ConnectionInterface $database,
    ) {}

    public function run(
        Fixture $fixture,
        bool $shuffle,
        bool $duplicate,
        int $seed,
        int $iterations = 4,
        ?CarbonImmutable $startingAt = null,
    ): ChaosReport {
        $startingAt ??= CarbonImmutable::now();

        $baseline = $this->pass($fixture, EventOrderer::inOrder(), 0, $startingAt);
        $passes = [$baseline];

        for ($i = 1; $i <= $iterations; $i++) {
            $orderer = new EventOrderer($shuffle, $duplicate, $seed);

            // Shuffling a timeline whose every step holds a single event is a
            // no-op. Reporting that as a passing chaos run would be coverage
            // theatre, so it is reported as what it is.
            if (! $this->hasSomethingToRearrange($fixture, $orderer)) {
                $passes[] = new PassResult(
                    pass: $i,
                    ordering: $orderer->describe(),
                    report: $baseline->report,
                    sideEffects: [],
                    finalEntitlements: [],
                    notApplicable: true,
                );

                continue;
            }

            $result = $this->pass($fixture, $orderer, $i, $startingAt);

            $passes[] = new PassResult(
                pass: $i,
                ordering: $orderer->describe(),
                report: $result->report,
                sideEffects: $result->sideEffects,
                finalEntitlements: $result->finalEntitlements,
                divergences: $this->compare($baseline, $result),
            );
        }

        return new ChaosReport($fixture->scenario, $seed, $passes);
    }

    /**
     * Every pass starts from the same world.
     *
     * Without this the second pass replays a timeline against subscriptions the
     * first pass already created, and diverges for reasons that have nothing to
     * do with ordering — which would make the whole comparison meaningless
     * while looking like a real finding.
     *
     * The transaction is always rolled back, including for the baseline: a
     * chaos run is a question, not a migration.
     */
    private function pass(Fixture $fixture, EventOrderer $orderer, int $pass, CarbonImmutable $startingAt): PassResult
    {
        // ReplayRunner also isolates its billable's connection. When it is
        // this connection, its transaction is deliberately a nested savepoint.
        // Keep the pass boundary for the explicitly supplied chaos connection.
        $transactionLevel = $this->database->transactionLevel();
        $this->database->beginTransaction();

        try {
            $report = $this->runner->run($fixture, $startingAt, $orderer, $pass);
        } finally {
            while ($this->database->transactionLevel() > $transactionLevel) {
                $this->database->rollBack();
            }
        }

        $last = $report->steps === [] ? null : $report->steps[count($report->steps) - 1];

        return new PassResult(
            pass: $pass,
            ordering: $orderer->describe(),
            report: $report,
            sideEffects: $report->sideEffects,
            finalEntitlements: $last instanceof StepResult ? $last->actualEntitlements : [],
        );
    }

    /** @return list<string> */
    private function compare(PassResult $baseline, PassResult $candidate): array
    {
        $divergences = [];

        if ($candidate->finalEntitlements !== $baseline->finalEntitlements) {
            $divergences[] = 'the application ended up entitled to something different: '
                .json_encode($baseline->finalEntitlements).' in order, '
                .json_encode($candidate->finalEntitlements).' '.$candidate->ordering;
        }

        foreach ($baseline->sideEffects as $effect => $count) {
            $seen = $candidate->sideEffects[$effect] ?? 0;

            if ($seen !== $count) {
                $divergences[] = "[{$effect}] happened {$count} time(s) in order and {$seen} time(s) {$candidate->ordering}";
            }
        }

        foreach ($candidate->sideEffects as $effect => $count) {
            if (! array_key_exists($effect, $baseline->sideEffects)) {
                $divergences[] = "[{$effect}] happened {$count} time(s) {$candidate->ordering} and never in order";
            }
        }

        return $divergences;
    }

    private function hasSomethingToRearrange(Fixture $fixture, EventOrderer $orderer): bool
    {
        if ($orderer->duplicate) {
            return true;
        }

        foreach ($fixture->steps as $step) {
            if ($step->isShuffleable()) {
                return true;
            }
        }

        return false;
    }
}
