<?php

namespace Impruthvi\CashierDunning\Tests;

use Illuminate\Foundation\Auth\User;
use Impruthvi\CashierDunning\CashierDunningServiceProvider;
use Laravel\Cashier\CashierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * Cashier is loaded because this package's tests need to run in an
     * application that actually has it — its webhook route and signature
     * middleware are part of what replay has to satisfy, and without the
     * provider there are no routes to post to.
     */
    protected function getPackageProviders($app)
    {
        return [
            CashierServiceProvider::class,
            CashierDunningServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        // Cashier defaults to App\Models\User, which does not exist in a
        // Testbench application. Pointed at a class that does, so checks which
        // verify the model resolves are testing themselves rather than the
        // absence of a skeleton app.
        config()->set('cashier.model', User::class);
    }
}
