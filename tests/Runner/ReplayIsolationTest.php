<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Chaos\ChaosRunner;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Tests\Support\BillingUser;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\User;
use Impruthvi\CashierDunning\Tests\TestCase;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(fn () => DunningMailer::reset());
afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

it('preserves caller transactions and existing billing rows', function (bool $chaos) {
    $database = DB::connection();
    $entryLevel = $database->transactionLevel();
    $database->beginTransaction();

    try {
        $existing = User::factory()->create(['stripe_id' => 'cus_existing']);
        $existing->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
        ]);

        $users = $database->table('users')->get()->all();
        $subscriptions = $database->table('subscriptions')->get()->all();
        $callerLevel = $database->transactionLevel();

        billableUser();
        registerDunningPolicy();

        $runner = app(ReplayRunner::class);
        $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate');

        $report = $chaos
            ? (new ChaosRunner($runner))
                ->run($fixture, shuffle: true, duplicate: false, seed: 7, iterations: 2)
            : $runner->run($fixture);

        expect($report->passed())->toBeTrue()
            ->and($database->transactionLevel())->toBe($callerLevel)
            ->and($database->table('users')->get()->all())->toEqual($users)
            ->and($database->table('subscriptions')->get()->all())->toEqual($subscriptions);

        $this->assertDatabaseCount('subscription_items', 0);
    } finally {
        $database->rollBack($entryLevel);
    }
})->with(['plain replay' => false, 'chaos replay' => true]);

it('rolls back factory writes and abandoned savepoints when application code throws', function (string $failureAt) {
    $database = DB::connection();
    $entryLevel = $database->transactionLevel();
    $database->beginTransaction();

    try {
        $existing = User::factory()->create(['stripe_id' => 'cus_existing']);
        $callerLevel = $database->transactionLevel();
        $failure = new RuntimeException('application failed during '.$failureAt);

        CashierDunning::createBillableUsing(function () use ($database, $failureAt, $failure): User {
            $user = User::factory()->create(['stripe_id' => 'cus_replay1']);

            // An application exception may leave a nested transaction open.
            // Cleanup must restore the entry level, not just pop one level.
            $database->beginTransaction();

            if ($failureAt === 'factory') {
                throw $failure;
            }

            return $user;
        });
        CashierDunning::resolveEntitlementsUsing(function () use ($failure): array {
            throw $failure;
        });

        $caught = null;

        try {
            app(ReplayRunner::class)->run(
                (new FixtureRepository)->find('trial-dunning-cancel-reactivate')
            );
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($failure)
            ->and($database->transactionLevel())->toBe($callerLevel)
            ->and(User::pluck('id')->all())->toBe([$existing->id]);

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('subscription_items', 0);
    } finally {
        $database->rollBack($entryLevel);
    }
})->with(['factory', 'resolver']);

it('isolates CLI replay on the configured billable non-default connection', function (bool $shuffle) {
    config()->set('database.connections.billing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    $this->createCashierTables('billing');

    Cashier::useCustomerModel(BillingUser::class);
    CashierDunning::createBillableUsing(fn () => BillingUser::create([
        'name' => 'Jenny',
        'email' => 'jenny@example.com',
        'stripe_id' => 'cus_replay1',
    ]));
    registerDunningPolicy();

    $defaultLevel = DB::connection()->transactionLevel();
    $billingLevel = DB::connection('billing')->transactionLevel();

    for ($run = 0; $run < 2; $run++) {
        $command = $this->artisan('billing:simulate', [
            'scenario' => 'trial-dunning-cancel-reactivate',
            '--shuffle' => $shuffle,
            '--iterations' => 2,
            '--seed' => 7,
        ])->expectsOutputToContain('All 46 assertions passed.');

        if ($shuffle) {
            $command->expectsOutputToContain('The application behaved identically across 3 ordering(s).');
        }

        $command->assertSuccessful()->run();

        foreach (['testing', 'billing'] as $connection) {
            $this->assertDatabaseCount('users', 0, $connection);
            $this->assertDatabaseCount('subscriptions', 0, $connection);
            $this->assertDatabaseCount('subscription_items', 0, $connection);
        }

        expect(DB::connection()->transactionLevel())->toBe($defaultLevel)
            ->and(DB::connection('billing')->transactionLevel())->toBe($billingLevel);
    }
})->with(['plain replay' => false, 'chaos replay' => true]);

/**
 * Point Cashier at a customer model on a second in-memory connection, so the
 * billable's connection and the application's default connection differ.
 */
function billableOnBillingConnection(TestCase $test): void
{
    config()->set('database.connections.billing', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    (fn () => $this->createCashierTables('billing'))->call($test);

    Cashier::useCustomerModel(BillingUser::class);
    CashierDunning::createBillableUsing(fn () => BillingUser::create([
        'name' => 'Jenny',
        'email' => 'jenny@example.com',
        'stripe_id' => 'cus_replay1',
    ]));
    registerDunningPolicy();
}

it('rolls back default-connection writes when the billable lives on another connection', function () {
    billableOnBillingConnection($this);

    // The most common multi-connection setup: billing data elsewhere, and the
    // application's own bookkeeping on the default connection.
    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        DB::connection()->table('users')->insert([
            'name' => 'audit',
            'email' => 'audit@example.com',
            'stripe_id' => (string) ($event->payload['id'] ?? 'evt'),
        ]);
    });

    $defaultLevel = DB::connection()->transactionLevel();
    $billingLevel = DB::connection('billing')->transactionLevel();

    $report = app(ReplayRunner::class)->run(
        (new FixtureRepository)->find('trial-dunning-cancel-reactivate')
    );

    expect($report->passed())->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe($defaultLevel)
        ->and(DB::connection('billing')->transactionLevel())->toBe($billingLevel);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('users', 0, 'billing');
    $this->assertDatabaseCount('subscriptions', 0, 'billing');
});

it('fails the run when application code commits the default-connection transaction the replay opened', function () {
    billableOnBillingConnection($this);

    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) === 'invoice.payment_failed' && DB::connection()->transactionLevel() > 0) {
            DB::connection()->commit();
        }
    });

    $defaultLevel = DB::connection()->transactionLevel();
    $billingLevel = DB::connection('billing')->transactionLevel();

    expect(fn () => app(ReplayRunner::class)->run(
        (new FixtureRepository)->find('trial-dunning-cancel-reactivate')
    ))->toThrow(SimulationFailed::class, 'could not undo its own writes');

    expect(DB::connection()->transactionLevel())->toBe($defaultLevel)
        ->and(DB::connection('billing')->transactionLevel())->toBe($billingLevel);
});

it('opens a single transaction when the billable uses the default connection', function () {
    billableUser();
    registerDunningPolicy();

    $entryLevel = DB::connection()->transactionLevel();
    $deepest = $entryLevel;

    CashierDunning::resolveEntitlementsUsing(function (User $billable) use (&$deepest): array {
        $deepest = max($deepest, DB::connection()->transactionLevel());

        return [];
    });

    app(ReplayRunner::class)->run((new FixtureRepository)->find('trial-dunning-cancel-reactivate'));

    expect($deepest)->toBe($entryLevel + 1)
        ->and(DB::connection()->transactionLevel())->toBe($entryLevel);
});
