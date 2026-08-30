<?php

namespace Impruthvi\CashierDunning\Commands;

use Illuminate\Console\Command;

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
        $this->components->error('Not implemented yet.');

        return self::FAILURE;
    }
}
