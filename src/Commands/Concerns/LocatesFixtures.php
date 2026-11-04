<?php

namespace Impruthvi\CashierDunning\Commands\Concerns;

/**
 * One answer to "where are this application's recordings?".
 *
 * Recording writes there and replay reads from there, so both have to ask the
 * same question. When they answered it separately, an unset fixtures.path sent
 * recordings to tests/fixtures/billing and replay looked nowhere but the
 * package's own corpus, so a fresh recording could not be replayed.
 */
trait LocatesFixtures
{
    private function applicationFixturePath(): string
    {
        $path = config('cashier-dunning.fixtures.path');

        return is_string($path) && $path !== ''
            ? $path
            : base_path('tests'.DIRECTORY_SEPARATOR.'fixtures'.DIRECTORY_SEPARATOR.'billing');
    }
}
