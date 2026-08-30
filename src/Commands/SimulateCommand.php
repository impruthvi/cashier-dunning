<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;
use Impruthvi\CashierDunning\Guards\Exceptions\UnsafeKey;
use Impruthvi\CashierDunning\Guards\KeyModeGuard;

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
        }

        $this->components->error('Not implemented yet.');

        return self::FAILURE;
    }

    private function stripeKey(): ?string
    {
        $key = config('cashier.secret');

        return is_string($key) ? $key : null;
    }
}
