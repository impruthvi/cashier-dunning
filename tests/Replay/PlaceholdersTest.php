<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Replay\Placeholders;

$start = CarbonImmutable::parse('2026-01-01T00:00:00Z');

it('resolves an identifier to a stable invented id', function () use ($start) {
    // Keeps the provider prefix, so anything that inspects an id to decide what
    // kind of object it has keeps working. Obviously fake, so an id in a failing
    // test says immediately that nothing here came from Stripe.
    expect((new Placeholders($start))->resolveString('{{sub_1}}'))->toBe('sub_replay1')
        ->and((new Placeholders($start))->resolveString('{{cus_2}}'))->toBe('cus_replay2');
});

it('resolves a whole-string timestamp to an integer', function () use ($start) {
    // Cashier does date arithmetic on these. A string would work until it did
    // not, somewhere far away from here.
    $resolved = (new Placeholders($start))->resolveString('{{t+14d1h}}');

    expect($resolved)->toBeInt()
        ->and($resolved)->toBe($start->addDays(14)->addHour()->getTimestamp());
});

it('resolves placeholders embedded in a path', function () use ($start) {
    expect((new Placeholders($start))->resolveString('/v1/subscriptions/{{sub_1}}'))
        ->toBe('/v1/subscriptions/sub_replay1');
});

it('resolves nested structures', function () use ($start) {
    $resolved = (new Placeholders($start))->resolve([
        'id' => '{{in_1}}',
        'data' => ['object' => ['subscription' => '{{sub_1}}', 'created' => '{{t+0d}}']],
    ]);

    expect($resolved['id'])->toBe('in_replay1')
        ->and($resolved['data']['object']['subscription'])->toBe('sub_replay1')
        ->and($resolved['data']['object']['created'])->toBe($start->getTimestamp());
});

it('leaves values that are not placeholders alone', function () use ($start) {
    $resolved = (new Placeholders($start))->resolve([
        'status' => 'past_due',
        'amount_due' => 3000,
        'cancel_at_period_end' => false,
        'canceled_at' => null,
    ]);

    expect($resolved)->toBe([
        'status' => 'past_due',
        'amount_due' => 3000,
        'cancel_at_period_end' => false,
        'canceled_at' => null,
    ]);
});

it('resolves multi-segment prefixes', function () use ($start) {
    expect((new Placeholders($start))->resolveString('{{sub_sched_1}}'))->toBe('sub_sched_replay1');
});

it('gives the same value for the same placeholder every time', function () use ($start) {
    $placeholders = new Placeholders($start);

    expect($placeholders->resolveString('{{sub_1}}'))
        ->toBe($placeholders->resolveString('{{sub_1}}'));
});
