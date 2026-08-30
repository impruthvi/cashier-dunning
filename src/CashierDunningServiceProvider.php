<?php

namespace Impruthvi\CashierDunning;

use Impruthvi\CashierDunning\Commands\SimulateCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CashierDunningServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cashier-dunning')
            ->hasConfigFile()
            ->hasCommand(SimulateCommand::class);
    }
}
