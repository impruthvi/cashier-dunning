<?php

use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Chaos\ChaosRunner;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Impruthvi\CashierDunning\Tests\Support\AfterCommitJob;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    AfterCommitJob::$handled = 0;
    billableUser();
    registerDunningPolicy();
});

afterEach(function () {
    CashierDunning::flush();
    AfterCommitJob::$handled = 0;
});

it('fails the CLI instead of reporting success for explicit after-commit work', function () {
    $queued = 0;
    Event::listen(JobQueued::class, function () use (&$queued): void {
        $queued++;
    });
    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) === 'invoice.payment_failed') {
            app(BusDispatcher::class)->dispatch((new AfterCommitJob)->afterCommit());
        }
    });

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('after-commit work that could not be observed')
        ->assertFailed();

    expect($queued)->toBe(0)
        ->and(AfterCommitJob::$handled)->toBe(0);

    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);
});

it('detects work deferred by queue connection configuration', function () {
    config()->set('queue.connections.after-commit-sync', [
        'driver' => 'sync',
        'after_commit' => true,
    ]);

    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) === 'invoice.payment_failed') {
            app(BusDispatcher::class)->dispatch(
                (new AfterCommitJob)->onConnection('after-commit-sync')
            );
        }
    });

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('after-commit work that could not be observed')
        ->assertFailed();

    expect(AfterCommitJob::$handled)->toBe(0);
});

it('fails direct chaos runs instead of returning a false clean report', function () {
    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) === 'invoice.payment_failed') {
            app(BusDispatcher::class)->dispatch((new AfterCommitJob)->afterCommit());
        }
    });

    $database = app('db')->connection();
    $entryLevel = $database->transactionLevel();

    try {
        (new ChaosRunner(
            new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)),
            app(EventDispatcher::class),
            $database,
        ))->run(
            (new FixtureRepository)->find('trial-dunning-cancel-reactivate'),
            shuffle: true,
            duplicate: false,
            seed: 7,
            iterations: 2,
        );
    } finally {
        expect($database->transactionLevel())->toBe($entryLevel);
    }
})->throws(SimulationFailed::class, 'after-commit work that could not be observed');

it('does not mistake a caller transaction callback for replay work', function () {
    $database = app('db')->connection();
    $entryLevel = $database->transactionLevel();
    $database->beginTransaction();

    try {
        $database->afterCommit(static fn () => null);

        $report = (new ReplayRunner(
            config(),
            app(Kernel::class),
            app(EntitlementResolver::class),
        ))->run((new FixtureRepository)->find('trial-dunning-cancel-reactivate'));

        expect($report->passed())->toBeTrue()
            ->and($database->transactionLevel())->toBe($entryLevel + 1);
    } finally {
        $database->rollBack($entryLevel);
    }
});
