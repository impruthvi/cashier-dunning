<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;
use Impruthvi\CashierDunning\Record\Exceptions\IncompleteRecording;
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
            $report = (new ReplayRunner(
                config(),
                $this->laravel->make(Kernel::class),
                $this->laravel->make(EntitlementResolver::class),
            ))->run($fixture);
        } catch (SimulationFailed $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        (new TimelineRenderer($this->output, (bool) $this->option('explain')))->render($report);

        $this->writeJsonReport($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
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

        $this->newLine();
        $this->line("  Recording <options=bold>{$scenario->name}</> against Stripe test mode");
        $this->newLine();

        foreach ($scenario->describeActions() as $action) {
            $this->line("  {$action}");
        }

        $this->newLine();

        try {
            $fixture = (new Recorder(
                Cashier::stripe(),
                $this->quarantineDirectory(),
            ))->record($scenario);
        } catch (IncompleteRecording $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $path = $this->fixtureDestination($fixture->scenario);

        FixtureFile::write($fixture, $path);

        $this->line("  Wrote <options=bold>{$path}</>");
        $this->line('  '.count($fixture->eventTypes()).' event(s) captured across '.count($fixture->steps).' step(s).');
        $this->newLine();

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

        $this->newLine();
        $this->line('  <options=bold>Available scenarios</>');
        $this->newLine();

        foreach ($scenarios as $scenario) {
            $this->line("  billing:simulate {$scenario}");
        }

        $this->newLine();

        return self::SUCCESS;
    }

    private function writeJsonReport(ReplayReport $report): void
    {
        $path = $this->option('json');

        if (! is_string($path) || $path === '') {
            return;
        }

        JsonReport::write($report, $path);

        $this->line("  Report written to <options=bold>{$path}</>");
        $this->newLine();
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
