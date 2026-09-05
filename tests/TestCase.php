<?php

namespace Impruthvi\CashierDunning\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierDunning\CashierDunningServiceProvider;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\TrackDunning;
use Impruthvi\CashierDunning\Tests\Support\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\CashierServiceProvider;
use Laravel\Cashier\Events\WebhookReceived;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    /**
     * Cashier is loaded because this package's tests need to run in an
     * application that actually has it — its webhook route, signature
     * middleware and subscription tables are part of what replay has to
     * satisfy, and without the provider there are no routes to post to.
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
        // Testbench application. This is the same call a real application makes
        // from a service provider.
        Cashier::useCustomerModel(User::class);

        // The application-side dunning policy the replay tests assert against.
        Event::listen(WebhookReceived::class, [TrackDunning::class, 'handle']);

        // An application that emails on failed payments, used to show what a
        // chaos run can see that an ordered run cannot.
        Event::listen(WebhookReceived::class, [DunningMailer::class, 'handle']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->createCashierTables();
    }

    /**
     * The tables Cashier writes to when a webhook arrives. Hand-rolled rather
     * than published from Cashier so the test schema stays fixed while
     * Cashier's own migrations evolve.
     */
    protected function createCashierTables(?string $connection = null): void
    {
        $schema = Schema::connection($connection);

        $schema->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('dunning_exhausted')->default(false);
            $table->timestamps();
        });

        $schema->create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('type');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        $schema->create('handled_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->index();
        });

        $schema->create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id')->unique();
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }
}
