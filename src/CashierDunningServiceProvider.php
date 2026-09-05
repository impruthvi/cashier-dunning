<?php

namespace Impruthvi\CashierDunning;

use Impruthvi\CashierDunning\Commands\DoctorCommand;
use Impruthvi\CashierDunning\Commands\SimulateCommand;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\Exceptions\InvalidResolver;
use Impruthvi\CashierDunning\Entitlements\NullResolver;
use Impruthvi\CashierDunning\Guards\OutboundGuard;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CashierDunningServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cashier-dunning')
            ->hasConfigFile()
            ->hasCommands([SimulateCommand::class, DoctorCommand::class]);
    }

    public function packageRegistered(): void
    {
        // A singleton because the guard registers its event listeners once and
        // then arms them per replay. Rebuilding it would stack a new set of
        // listeners on the dispatcher for every run.
        $this->app->singleton(OutboundGuard::class);

        // Bound rather than resolved eagerly: a service provider that calls
        // CashierDunning::resolveEntitlementsUsing() may run after this one.
        $this->app->bind(EntitlementResolver::class, function ($app): EntitlementResolver {
            if ($registered = CashierDunning::entitlementResolver()) {
                return $registered;
            }

            $configured = $app['config']->get('cashier-dunning.entitlements.resolver');

            if ($configured === null) {
                return new NullResolver;
            }

            $resolver = is_string($configured) ? $app->make($configured) : $configured;

            if (! $resolver instanceof EntitlementResolver) {
                throw InvalidResolver::notResolvable(get_debug_type($configured));
            }

            return $resolver;
        });
    }
}
