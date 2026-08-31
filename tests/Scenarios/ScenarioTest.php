<?php

use Impruthvi\CashierDunning\Fixtures\FixtureRepository;
use Impruthvi\CashierDunning\Fixtures\Manifest;
use Impruthvi\CashierDunning\Scenarios\Action;
use Impruthvi\CashierDunning\Scenarios\ActionType;
use Impruthvi\CashierDunning\Scenarios\Scenario;
use Impruthvi\CashierDunning\Scenarios\ScenarioRepository;
use Impruthvi\CashierDunning\Scenarios\ScenarioStep;

it('ships the dunning scenario the package is named after', function () {
    expect((new ScenarioRepository)->names())->toContain('trial-dunning-cancel-reactivate');
});

it('declares the events a dunning recording is worthless without', function () {
    // A recording that never captured invoice.payment_failed is not a dunning
    // recording, however plausible the file looks.
    $manifest = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->manifest;

    expect($manifest->required)->toContain('invoice.payment_failed')
        ->and($manifest->required)->toContain('customer.subscription.deleted');
});

it('tolerates events Stripe emits only for some account configurations', function () {
    // Requiring invoice.finalized would make the recorder work only for
    // accounts configured like the author's.
    $manifest = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->manifest;

    expect($manifest->optional)->toContain('invoice.finalized')
        ->and($manifest->required)->not->toContain('invoice.finalized');
});

it('matches the manifest of the fixture recorded from it', function () {
    // The scenario and the fixture are the two halves of the same promise. If
    // they drift apart, the fixture is validated against something nobody
    // intended to record.
    $scenario = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate');
    $fixture = (new FixtureRepository)->find('trial-dunning-cancel-reactivate');

    expect($fixture->manifest->required)->toBe($scenario->manifest->required);
});

it('provokes dunning with a card that authorises and then fails', function () {
    // A card that declines outright never creates the subscription to dun. This
    // one gets through setup and fails on the first real charge, which is the
    // only reliable way to reach a retry window on demand.
    $subscribe = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->steps[0]->action;

    expect($subscribe->type)->toBe(ActionType::Subscribe)
        ->and($subscribe->parameters['card'])->toBe('pm_card_chargeCustomerFail')
        ->and($subscribe->parameters['trial_days'])->toBe(14);
});

it('lands payment steps past the hour Stripe leaves invoices in draft', function () {
    // A scenario that moved in whole days would advance straight past the
    // charge it came to watch.
    $offsets = array_map(
        fn (ScenarioStep $step): string => $step->advanceTo->toString(),
        (new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->steps
    );

    expect($offsets)->toContain('14d1h')
        ->and($offsets)->toContain('17d1h');
});

it('knows how far the clock has to travel before starting', function () {
    // Stripe refuses to advance a test clock more than two billing periods at a
    // time, so the recorder needs this before it moves anything.
    expect((new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->span()->toString())
        ->toBe('30d');
});

it('separates the steps that change the account from the ones that watch', function () {
    $scenario = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate');

    expect($scenario->steps)->toHaveCount(6)
        ->and($scenario->mutatingSteps())->toHaveCount(2);
});

it('describes what it will do without doing any of it', function () {
    // A scenario has to be printable before it is runnable: nobody should have
    // to run a recording to find out what it will create in their account.
    $described = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate')->describeActions();

    expect($described[0])->toBe('+0d  subscribe to price_monthly with pm_card_chargeCustomerFail after a 14 day trial')
        ->and($described[1])->toBe('+11d  wait');
});

it('round-trips through an array', function () {
    $scenario = (new ScenarioRepository)->find('trial-dunning-cancel-reactivate');

    expect(Scenario::fromArray($scenario->toArray())->toArray())->toBe($scenario->toArray());
});

it('returns null for a scenario it does not have', function () {
    expect((new ScenarioRepository)->find('nope'))->toBeNull();
});

it('defaults a step with no action to waiting', function () {
    expect(ScenarioStep::at('7d', 'nothing happens')->action->type)->toBe(ActionType::Wait);
});

it('knows which actions touch Stripe', function () {
    expect(Action::wait()->type->touchesStripe())->toBeFalse()
        ->and(Action::cancel()->type->touchesStripe())->toBeTrue();
});

it('reports a manifest as unsatisfied when a required event never arrived', function () {
    $manifest = new Manifest(required: ['a', 'b'], optional: ['c']);

    expect($manifest->isSatisfiedBy(['a']))->toBeFalse()
        ->and($manifest->missingFrom(['a']))->toBe(['b'])
        ->and($manifest->isSatisfiedBy(['a', 'b']))->toBeTrue();
});

it('surfaces events nobody declared without failing on them', function () {
    // Stripe adds event types, and an account with automatic tax emits things
    // this scenario never asked about. Worth a maintainer's attention, not a
    // failed recording.
    $manifest = new Manifest(required: ['a'], optional: ['b']);

    expect($manifest->undeclaredIn(['a', 'b', 'z', 'z']))->toBe(['z'])
        ->and($manifest->isSatisfiedBy(['a', 'z']))->toBeTrue();
});
