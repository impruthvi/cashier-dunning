<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\DunningNotification;
use Impruthvi\CashierDunning\Tests\Support\User;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    DunningMailer::reset();

    // The array transport stands in for real delivery: anything that reaches it
    // is something that would have reached a customer on a machine configured
    // with live SMTP credentials.
    config()->set('mail.default', 'array');
});

afterEach(function () {
    CashierDunning::flush();
    DunningMailer::reset();
});

function plainReplay(): ReplayReport
{
    billableUser();
    registerDunningPolicy();

    return (new ReplayRunner(config(), app(Kernel::class), app(EntitlementResolver::class)))
        ->run((new FixtureRepository)->find('trial-dunning-cancel-reactivate'));
}

function delivered(): int
{
    return Mail::getSymfonyTransport()->messages()->count();
}

it('records the mail a plain replay provoked and delivers none of it', function () {
    // The ordered path, not chaos. This is the command the README leads with,
    // and it drives the application's real dunning listener.
    $report = plainReplay();

    expect($report->passed())->toBeTrue()
        ->and($report->sideEffects)->toHaveKey('mail:jenny@example.com')
        ->and($report->sideEffects['mail:jenny@example.com'])->toBeGreaterThan(0)
        ->and($report->blockedDeliveries)->toBe($report->sideEffects['mail:jenny@example.com'])
        ->and(delivered())->toBe(0);
});

it('blocks a notification without hiding that the application sent one', function () {
    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) !== 'invoice.payment_failed') {
            return;
        }

        Cashier::findBillable($event->payload['data']['object']['customer'] ?? null)
            ?->notify(new DunningNotification);
    });

    $report = plainReplay();

    expect($report->sideEffects)->toHaveKey('notification:'.DunningNotification::class)
        ->and(delivered())->toBe(0);
});

it('reports nothing blocked when the application sends nothing', function () {
    // Silence has to be distinguishable from suppression, or the blocked count
    // means nothing.
    Event::forget(WebhookReceived::class);
    registerDunningPolicy();

    $report = plainReplay();

    expect($report->sideEffects)->toBe([])
        ->and($report->blockedDeliveries)->toBe(0);
});

it('leaves the application able to send mail before and after a replay', function () {
    // The listeners stay registered for the life of the process and the mail
    // transports are swapped for the run, so the thing that would break an
    // application is either of those not being undone.
    //
    // Counted on each side rather than summed: restoring the transports rebuilds
    // the mailers, which discards the array transport holding the first message.
    // That is delivery working, not delivery lost — a real SMTP transport buffers
    // nothing.
    Mail::raw('before', fn ($message) => $message->to('before@example.com')->subject('before'));
    expect(delivered())->toBe(1);

    plainReplay();

    Mail::raw('after', fn ($message) => $message->to('after@example.com')->subject('after'));
    expect(delivered())->toBe(1);
});

it('leaves the application able to notify before and after a replay', function () {
    // The mail equivalent above existed from the start; this one did not, and
    // its absence was the highest-consequence gap in the guard. If the
    // NotificationSending listener ever failed to go inert, the application
    // would stop sending every notification, forever, from the first replay on —
    // silently, with the suite still green.
    $user = User::factory()->create(['stripe_id' => 'cus_before', 'email' => 'before@example.test']);

    $user->notify(new DunningNotification);
    expect(delivered())->toBe(1);

    plainReplay();

    $user->notify(new DunningNotification);
    expect(delivered())->toBe(1);
});

it('runs the application toMail() during a replay instead of short-circuiting it', function () {
    // The reason this package refuses to use Mail::fake() is that a fake stops
    // the application's own message-building code from running, and that code is
    // where the bug lives. Blocking at NotificationSending had the same effect
    // for notifications: it returned before the channel ever called toMail().
    DunningNotification::$built = 0;

    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) !== 'invoice.payment_failed') {
            return;
        }

        Cashier::findBillable($event->payload['data']['object']['customer'] ?? null)
            ?->notify(new DunningNotification);
    });

    $report = plainReplay();

    expect(DunningNotification::$built)->toBeGreaterThan(0)
        ->and($report->sideEffects)->toHaveKey('notification:'.DunningNotification::class)
        ->and(delivered())->toBe(0);
});

it('blocks delivery even when an application listener answers MessageSending first', function () {
    // Dispatcher::until() stops at the first listener that returns non-null, so
    // a guard that relied on returning false could simply never be consulted.
    // Delivery is stopped by the swapped transport, which no listener can
    // out-rank.
    Event::listen(MessageSending::class, fn (): bool => true);

    plainReplay();

    expect(delivered())->toBe(0);
});

it('records every recipient, not only the To line', function () {
    Event::listen(WebhookReceived::class, function (WebhookReceived $event): void {
        if (($event->payload['type'] ?? null) !== 'invoice.payment_failed') {
            return;
        }

        Mail::raw('dunning', function ($message): void {
            $message->to('to@example.test')->cc('cc@example.test')->bcc('bcc@example.test');
        });
    });

    $report = plainReplay();

    // Sorted and de-duplicated so the same set of people always keys the same.
    expect($report->sideEffects)->toHaveKey('mail:bcc@example.test,cc@example.test,to@example.test');
});

it('tells whoever ran the command what was attempted and that it was stopped', function () {
    billableUser();
    registerDunningPolicy();

    $this->artisan('billing:simulate trial-dunning-cancel-reactivate')
        ->expectsOutputToContain('mail:jenny@example.com')
        ->expectsOutputToContain('outbound delivery(s) blocked')
        ->assertSuccessful();

    expect(delivered())->toBe(0);
});
