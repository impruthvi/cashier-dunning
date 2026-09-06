<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\Chaos\ChaosReport;
use Impruthvi\CashierDunning\Chaos\ChaosRunner;
use Impruthvi\CashierDunning\Commands\Concerns\WritesToConsole;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
use Impruthvi\CashierDunning\Record\RecordAndVerify;
use Impruthvi\CashierDunning\Record\Recorder;
use Impruthvi\CashierDunning\Reporting\JsonReport;
use Impruthvi\CashierDunning\Reporting\TimelineRenderer;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Laravel\Cashier\Cashier;

class SimulateCommand extends Command
{
    use WritesToConsole;

    public $signature = 'billing:simulate
        {scenario? : Scenario to run, e.g. trial-dunning-cancel-reactivate}
        {--record : Record against real Stripe instead of replaying a fixture}
        {--mine : Record against your own Stripe test account}
        {--explain : Show why each entitlement changed}
        {--shuffle : Replay events in a different order}
        {--duplicate : Replay with duplicated events}
        {--seed= : Seed for deterministic shuffling}
        {--iterations=4 : Number of chaos passes}
        {--json= : Write a machine-readable report to this path}';

    public $description = 'Replay a Stripe billing lifecycle and prove your app handles it';

    public function handle(): int
    {
        // Checked here rather than inside the recorder, so that no later code
        // path can reach Stripe without passing it. Replay is deliberately not
        // guarded: it never reads the key.
        if ($this->option('record')) {
            try {
                KeyModeGuard::assertSafeToRecord($this->stripeKey());
            } catch (UnsafeKey $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            return $this->record();
        }

        $repository = new FixtureRepository($this->fixturePath());
        $scenario = $this->argument('scenario');

        if (! is_string($scenario) || $scenario === '') {
            return $this->listScenarios($repository);
        }

        try {
            $fixture = $repository->find($scenario);
        } catch (InvalidFixture $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $report = $this->runner()->run($fixture);
        } catch (SimulationFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        (new TimelineRenderer($this->display(), (bool) $this->option('explain')))->render($report);

        $this->writeJsonReport($report);

        if (! $report->passed()) {
            return self::FAILURE;
        }

        // Chaos runs after the ordered pass, not instead of it. A timeline that
        // does not work in the order Stripe sent it has a plainer problem than
        // ordering, and reporting the harder failure first would bury it.
        if ($this->option('shuffle') || $this->option('duplicate')) {
            return $this->chaos($fixture);
        }

        return self::SUCCESS;
    }

    /**
     * Record a scenario against a real Stripe test account.
     *
     * Slow and rate limited — twenty invoices per subscription per day — so the
     * scenario is printed before anything is created. Nobody should discover
     * what a recording does to their account by watching it happen.
     */
    private function record(): int
    {
        $name = $this->argument('scenario');
        $scenario = is_string($name) ? (new ScenarioRepository)->find($name) : null;

        if ($scenario === null) {
            $this->components->error(sprintf(
                'Unknown scenario [%s]. Available: %s.',
                is_string($name) ? $name : '',
                implode(', ', (new ScenarioRepository)->names()),
            ));

            return self::FAILURE;
        }

        $this->write('');
        $this->write("  Recording <options=bold>{$scenario->name}</> against Stripe test mode");
        $this->write('');

        foreach ($scenario->describeActions() as $action) {
            $this->write("  {$action}");
        }

        $this->write('');

        try {
            ['fixture' => $fixture, 'report' => $report] = (new RecordAndVerify(
                new Recorder(Cashier::stripe(), $this->quarantineDirectory()),
                $this->runner(),
            ))->record($scenario);
        } catch (IncompleteRecording $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (SimulationFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // A recording that cannot be replayed is worse than no recording: it
        // looks like coverage. Nothing reaches disk until it has worked once.
        if (! $report->passed()) {
            $this->components->error('The recording could not be replayed, so it was not written.');
            (new TimelineRenderer($this->display()))->render($report);

            return self::FAILURE;
        }

        $path = $this->fixtureDestination($fixture->scenario);

        FixtureFile::write($fixture, $path);

        $this->write("  Wrote <options=bold>{$path}</>");
        $this->write('  '.count($fixture->eventTypes()).' event(s) across '.count($fixture->steps).' step(s), replayed clean.');
        $this->write('');

        return self::SUCCESS;
    }

    private function fixtureDestination(string $scenario): string
    {
        $base = $this->fixturePath() ?? base_path('tests'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'billing');

        return $base.DIRECTORY_SEPARATOR.'stripe'.DIRECTORY_SEPARATOR.$scenario.'.json';
    }

    private function quarantineDirectory(): string
    {
        $base = $this->fixturePath() ?? base_path('tests'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'billing');

        return $base.DIRECTORY_SEPARATOR.'quarantine';
    }

    private function listScenarios(FixtureRepository $repository): int
    {
        $scenarios = array_keys($repository->scenarios());

        if ($scenarios === []) {
            $this->components->error('No fixtures found. Record one with `billing:simulate --record`.');

            return self::FAILURE;
        }

        $this->write('');
        $this->write('  <options=bold>Available scenarios</>');
        $this->write('');

        foreach ($scenarios as $scenario) {
            $this->write("  billing:simulate {$scenario}");
        }

        $this->write('');

        return self::SUCCESS;
    }

    /**
     * Replay the same timeline under orderings Stripe is entitled to use.
     *
     * Stripe guarantees at-least-once delivery and no ordering; almost nobody
     * tests against that, because live Stripe cannot be asked to redeliver an
     * event or to reshuffle the ones it sent at a single moment. A fixture can.
     *
     * Within a step, not across steps — see EventOrderer for why that
     * distinction is load-bearing rather than an implementation detail.
     */
    private function chaos(Fixture $fixture): int
    {
        $seed = $this->option('seed');
        $seed = is_numeric($seed) ? (int) $seed : random_int(1, 999999);

        $report = (new ChaosRunner(
            $this->runner(),
            $this->laravel->make('db')->connection(),
        ))->run(
            fixture: $fixture,
            shuffle: (bool) $this->option('shuffle'),
            duplicate: (bool) $this->option('duplicate'),
            seed: $seed,
            iterations: max(1, (int) $this->option('iterations')),
        );

        $this->renderChaos($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function renderChaos(ChaosReport $report): void
    {
        $this->write('  <options=bold>Chaos</>  same events, orders Stripe is entitled to use');
        $this->write('');

        foreach ($report->passes as $pass) {
            // Padded inside the colour tags, so "ok", "n/a" and "FAIL" occupy
            // the same width and the pass column keeps a straight left edge.
            // A ragged edge costs the reader the vertical scan the marks exist
            // to provide, which is the whole point of a column of verdicts.
            [$word, $colour] = match (true) {
                $pass->notApplicable => ['n/a', 'gray'],
                $pass->passed() => ['ok', 'green'],
                default => ['FAIL', 'red'],
            };

            $marker = sprintf('<fg=%s>%s</>', $colour, str_pad($word, 4));

            $this->write("  {$marker}  pass {$pass->pass}: {$pass->ordering}");

            if (! $pass->report->passed()) {
                (new TimelineRenderer(
                    $this->display(),
                    (bool) $this->option('explain'),
                ))->renderFailures($pass->report);
            }

            foreach ($pass->divergences as $divergence) {
                $this->write("        <fg=red>{$divergence}</>");
            }
        }

        $this->write('');
        $this->write($report->passed()
            ? '  <fg=green>PASS</>  '.$report->verdict()
            : '  <fg=red>FAIL</>  '.$report->verdict());
        $this->write('');
    }

    private function writeJsonReport(ReplayReport $report): void
    {
        $path = $this->option('json');

        if (! is_string($path) || $path === '') {
            return;
        }

        JsonReport::write($report, $path);

        $this->write("  Report written to <options=bold>{$path}</>");
        $this->write('');
    }

    private function runner(): ReplayRunner
    {
        return new ReplayRunner(
            config(),
            $this->laravel->make(Kernel::class),
            $this->laravel->make(EntitlementResolver::class),
        );
    }

    private function stripeKey(): ?string
    {
        $key = config('cashier.secret');

        return is_string($key) ? $key : null;
    }

    private function fixturePath(): ?string
    {
        $path = config('cashier-dunning.fixtures.path');

        return is_string($path) ? $path : null;
    }
}
