<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Runner\StepResult;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;

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
        {--iterations=4 : Number of chaos passes}';

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

            $this->components->error('Recording is not implemented yet.');

            return self::FAILURE;
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

        $this->render($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
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

    private function render(ReplayReport $report): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$report->scenario}</>  replayed with no Stripe account");
        $this->newLine();

        foreach ($report->steps as $step) {
            $this->renderStep($step);
        }

        $this->newLine();

        // The exit code is decided by passed(), which requires assertions > 0.
        // A run that delivered nothing and compared nothing must not read as
        // success — that is the failure this package exists to prevent.
        $report->passed()
            ? $this->line("  <fg=green>PASS</>  {$report->verdict()}")
            : $this->line("  <fg=red>FAIL</>  {$report->verdict()}");

        $this->newLine();
    }

    private function renderStep(StepResult $step): void
    {
        $marker = $step->failed() ? '<fg=red>✗</>' : '<fg=green>✓</>';

        $this->line("  {$marker} <options=bold>+{$step->advanceTo}</>  {$step->label}");

        foreach ($step->events as $event) {
            $status = $event->failed() ? "<fg=red>{$event->status}</>" : "<fg=gray>{$event->status}</>";
            $this->line("      {$event->type}  {$status}");

            if ($this->option('explain')) {
                foreach ($event->changes as $change) {
                    $this->line('        <fg=yellow>'.$change->describe().'</>');
                }
            }
        }

        foreach ($step->mismatches as $mismatch) {
            $this->line("      <fg=red>{$mismatch}</>");
        }

        foreach ($step->events as $event) {
            if ($event->failure !== null) {
                $this->line("      <fg=red>{$event->failure}</>");
            }
        }
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
