<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\Allowlist;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Guards\KeyMode;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * Reports what this installation can and cannot do, before it tries.
 *
 * Recording is rate limited by Stripe — twenty invoices per subscription per
 * day — and a test clock takes real seconds to advance. Discovering a
 * misconfiguration halfway through a recording costs a scenario's worth of
 * quota and leaves a half-built clock behind. Every check here is offline, so
 * finding out is free.
 */
class DoctorCommand extends Command
{
    public $signature = 'billing:doctor';

    public $description = 'Check whether this application can record and replay billing scenarios';

    private bool $failed = false;

    public function handle(): int
    {
        $this->write('');
        $this->write('  <options=bold>cashier-dunning</> environment check');
        $this->write('');

        $mode = $this->checkStripeKey();
        $this->checkCashier();
        $this->checkWebhookSecret();
        $this->checkEntitlementResolver();
        $this->checkFixtures();
        $this->checkScenarios();

        $this->write('');
        $this->write('  <options=bold>Replay</>  works with no Stripe account, key or network.');
        $this->write('  <options=bold>Record</>  '.($mode->canRecord()
            ? 'ready.'
            : 'blocked: '.$mode->describe().'.'));
        $this->write('');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkStripeKey(): KeyMode
    {
        $key = config('cashier.secret');
        $key = is_string($key) ? $key : null;
        $mode = KeyModeGuard::detect($key);

        // The key itself is never printed. CI logs outlive the runs that made
        // them, and a leaked live key is a worse outcome than a vague message.
        $shown = KeyModeGuard::redact($key);

        match ($mode) {
            KeyMode::Test => $this->ok("Stripe key: {$shown} (test mode)"),
            KeyMode::Live => $this->caution("Stripe key: {$shown} — LIVE. Recording is refused; replay is unaffected."),
            KeyMode::Missing => $this->ok('Stripe key: not set. Fine for replay; recording needs a test key.'),
            KeyMode::Unrecognised => $this->caution("Stripe key: {$shown} — not a recognised format, so recording is refused."),
        };

        return $mode;
    }

    private function checkCashier(): void
    {
        // Cashier has no config key for this. The billable model is a static on
        // Cashier itself, set with useCustomerModel() and defaulting to
        // App\Models\User — which is why a package that has been renamed or a
        // Laravel application with a different namespace fails deep inside a
        // webhook handler rather than at boot.
        $model = Cashier::$customerModel;

        class_exists($model)
            ? $this->ok("Cashier billable model: {$model}")
            : $this->problem(
                "Cashier billable model [{$model}] does not exist. Set it in a ".
                'service provider: Cashier::useCustomerModel(User::class).'
            );
    }

    private function checkWebhookSecret(): void
    {
        $secret = config('cashier.webhook.secret');

        // Cashier only applies signature verification when this is set. Replay
        // signs its payloads either way, so both states are correct; which one
        // you are in changes what a replay actually proves.
        is_string($secret) && $secret !== ''
            ? $this->ok('Webhook secret: set. Replayed events are signed and verified.')
            : $this->caution('Webhook secret: not set. Cashier skips signature verification, so replay cannot prove your signature handling works.');
    }

    private function checkEntitlementResolver(): void
    {
        // Asks what is registered rather than what the container returns. The
        // container always returns something — that is what NullResolver is for
        // — so resolving would answer a different question than the one an
        // operator is asking.
        $inCode = CashierDunning::entitlementResolver() !== null;
        $configured = config('cashier-dunning.entitlements.resolver');

        if (! $inCode && $configured === null) {
            $this->caution('Entitlement resolver: none registered. Timelines will show subscription state but no entitlements.');

            return;
        }

        try {
            $resolver = $this->laravel->make(EntitlementResolver::class);
        } catch (Throwable $e) {
            $this->problem('Entitlement resolver: '.$e->getMessage());

            return;
        }

        $this->ok('Entitlement resolver: '.($inCode
            ? 'closure registered in code'
            : $resolver::class));
    }

    private function checkFixtures(): void
    {
        $path = config('cashier-dunning.fixtures.path');
        $repository = new FixtureRepository(is_string($path) ? $path : null);

        $files = $repository->files();

        if ($files === []) {
            $this->caution('Fixtures: none found. Record one with `billing:simulate --record`.');

            return;
        }

        $allowlist = new Allowlist;
        $broken = 0;

        foreach ($files as $file) {
            try {
                $fixture = FixtureFile::read($file);

                foreach ($fixture->steps as $step) {
                    foreach ($step->events as $event) {
                        $allowlist->assert($event);
                    }
                }

                if (! $fixture->satisfiesManifest()) {
                    throw new \RuntimeException(
                        'missing required events: '.implode(', ', $fixture->missingRequiredEvents())
                    );
                }
            } catch (Throwable $e) {
                $broken++;
                $this->problem('Fixture ['.basename($file).']: '.$e->getMessage());
            }
        }

        if ($broken === 0) {
            $this->ok(count($files).' fixture(s) valid in '.implode(', ', $repository->paths()));
        }
    }

    /**
     * Which stories this installation can record, and which it already has.
     *
     * Recording costs Stripe quota and real minutes, so knowing that a scenario
     * is already recorded is worth a line — and knowing that one is not is what
     * tells you the gap is a missing recording rather than a broken replay.
     */
    private function checkScenarios(): void
    {
        $path = config('cashier-dunning.fixtures.path');
        $recorded = array_keys((new FixtureRepository(is_string($path) ? $path : null))->scenarios());

        foreach ((new ScenarioRepository)->all() as $name => $scenario) {
            in_array($name, $recorded, true)
                ? $this->ok("Scenario [{$name}]: recorded.")
                : $this->caution("Scenario [{$name}]: not recorded yet. `billing:simulate {$name} --record`.");
        }
    }

    private function ok(string $message): void
    {
        $this->write("  <fg=green>✓</> {$message}");
    }

    private function caution(string $message): void
    {
        $this->write("  <fg=yellow>!</> {$message}");
    }

    private function problem(string $message): void
    {
        $this->failed = true;
        $this->write("  <fg=red>✗</> {$message}");
    }

    /**
     * The raw console stream, not Laravel's OutputStyle, which collapses runs
     * of spaces and would flatten this report's two-column layout.
     */
    private function write(string $line): void
    {
        $this->output->getOutput()->writeln($line);
    }
}
