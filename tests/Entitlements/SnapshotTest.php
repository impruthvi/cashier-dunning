<?php

use Impruthvi\CashierDunning\Entitlements\Snapshot;

it('sorts features so an unordered resolver does not churn fixtures', function () {
    expect(Snapshot::of(['teams' => true, 'api' => false, 'projects' => 10])->toArray())
        ->toBe(['api' => false, 'projects' => 10, 'teams' => true]);
});

it('treats two snapshots with the same features as equal regardless of order', function () {
    expect(Snapshot::of(['a' => 1, 'b' => 2])->equals(Snapshot::of(['b' => 2, 'a' => 1])))->toBeTrue();
});

it('reports no changes when nothing moved', function () {
    expect(Snapshot::of(['teams' => true])->diff(Snapshot::of(['teams' => true])))->toBe([]);
});

it('reports the features that changed', function () {
    $changes = Snapshot::of(['teams' => true, 'projects' => 10])
        ->diff(Snapshot::of(['teams' => false, 'projects' => 10]));

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->feature)->toBe('teams')
        ->and($changes[0]->from)->toBeTrue()
        ->and($changes[0]->to)->toBeFalse();
});

it('treats a feature that disappears as a change against null', function () {
    // A resolver that stops reporting a feature has changed behaviour. Ignoring
    // it would hide a revocation.
    $changes = Snapshot::of(['api' => true])->diff(Snapshot::empty());

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->to)->toBeNull()
        ->and($changes[0]->isLoss())->toBeTrue();
});

it('distinguishes gains from losses across booleans and limits', function () {
    $changes = Snapshot::of(['api' => false, 'projects' => 10])
        ->diff(Snapshot::of(['api' => true, 'projects' => 0]));

    expect($changes[0]->feature)->toBe('api')
        ->and($changes[0]->isGain())->toBeTrue()
        ->and($changes[1]->feature)->toBe('projects')
        ->and($changes[1]->isLoss())->toBeTrue();
});

it('treats a change between two strings as neither gain nor loss', function () {
    // Plan names have no order. Calling a move from "pro" to "starter" a loss
    // would be a guess, and --explain would report it as fact.
    $change = Snapshot::of(['plan' => 'pro'])->diff(Snapshot::of(['plan' => 'starter']))[0];

    expect($change->isGain())->toBeFalse()
        ->and($change->isLoss())->toBeFalse();
});

it('describes a change in one line', function () {
    $change = Snapshot::of(['api' => true])->diff(Snapshot::of(['api' => false]))[0];

    expect($change->describe())->toBe('api: true -> false')
        ->and($change->causedBy(['type' => 'invoice.payment_failed'])->describe())
        ->toBe('api: true -> false (at invoice.payment_failed)');
});
