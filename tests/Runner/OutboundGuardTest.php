<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Runner\ReplayReport;
use Impruthvi\CashierDunning\Runner\ReplayRunner;
use Impruthvi\CashierDunning\Tests\Support\DunningMailer;
use Impruthvi\CashierDunning\Tests\Support\DunningNotification;
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
    // The listeners stay registered for the life of the process, so the thing
    // that would break an application is them staying armed. Mail on either
    // side of a replay has to go out normally.
    Mail::raw('before', fn ($message) => $message->to('before@example.com')->subject('before'));

    plainReplay();

    Mail::raw('after', fn ($message) => $message->to('after@example.com')->subject('after'));

    expect(delivered())->toBe(2);
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
