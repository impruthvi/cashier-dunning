<?php

use Carbon\CarbonImmutable;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\Normalizer;
use Impruthvi\CashierDunning\Fixtures\Placeholder;
use Impruthvi\CashierDunning\Replay\Placeholders;

// Recording writes placeholders and replay reads them back, in two modules that
// never meet in a run. These tests cross from one to the other, so a change to
// either side that the other does not understand fails here rather than as a
// replayed request that matches nothing.

$start = CarbonImmutable::parse('2026-01-01T00:00:00Z');

it('resolves every kind recording can mint back to an id of the same kind', function (string $kind) use ($start) {
    $normalizer = new Normalizer($start->getTimestamp());

    $first = $normalizer->placeholderFor("{$kind}_1RealAbc");
    $second = $normalizer->placeholderFor("{$kind}_2RealXyz");

    $placeholders = new Placeholders($start);

    expect($placeholders->resolveString($first))->toBe("{$kind}_replay1")
        ->and($placeholders->resolveString($second))->toBe("{$kind}_replay2");
})->with(Placeholder::KINDS);

it('mints the same placeholder for an id named in a payload and named alone', function () use ($start) {
    $normalizer = new Normalizer($start->getTimestamp());

    $payload = $normalizer->normalize(['id' => 'sub_1PxyzABC', 'customer' => 'cus_9Def']);

    expect($normalizer->placeholderFor('sub_1PxyzABC'))->toBe($payload['id'])
        ->and($normalizer->placeholderFor('not-an-id'))->toBe('not-an-id');
});

it('resolves a minute-aligned timestamp to the epoch it was recorded from', function (int $minutes) use ($start) {
    $epoch = $start->addMinutes($minutes)->getTimestamp();

    $recorded = (new Normalizer($start->getTimestamp()))->normalize(['trial_end' => $epoch]);

    expect((new Placeholders($start))->resolve($recorded))->toBe(['trial_end' => $epoch]);
})->with([0, 1, 60, 60 * 24 * 14, 60 * 24 * 17 + 61]);

it('floors seconds to the minute, and resolves to the floor', function () use ($start) {
    // Lossy by design: offsets are written in minutes, so two recordings a few
    // seconds apart stay byte-identical.
    $recorded = (new Normalizer($start->getTimestamp()))->normalize([
        'trial_end' => $start->addMinutes(5)->addSeconds(59)->getTimestamp(),
    ]);

    expect($recorded['trial_end'])->toBe('{{t+5m}}')
        ->and((new Placeholders($start))->resolve($recorded)['trial_end'])
        ->toBe($start->addMinutes(5)->getTimestamp());
});

it('pins a timestamp before the scenario start to the start', function () use ($start) {
    // Lossy by design: a timeline offset cannot be negative.
    $recorded = (new Normalizer($start->getTimestamp()))->normalize([
        'created' => $start->subDay()->getTimestamp(),
    ]);

    expect($recorded['created'])->toBe('{{t+0d}}')
        ->and((new Placeholders($start))->resolve($recorded)['created'])
        ->toBe($start->getTimestamp());
});

it('refuses a fixture holding a token that is not a placeholder', function (string $token, string $where) {
    $step = ['advance_to' => '0d', 'label' => 'start', 'events' => [['id' => '{{evt_1}}', 'type' => 'invoice.paid']]];

    if ($where === 'event') {
        $step['events'][0]['data'] = ['object' => ['subscription' => $token]];
    } else {
        $step['api_responses'] = [[
            'match' => ['method' => 'GET', 'path' => "/v1/subscriptions/{$token}"],
            'response' => ['status' => 200, 'body' => ['id' => '{{sub_1}}']],
        ]];
    }

    Fixture::fromArray([
        'format_version' => 1,
        'provider' => 'stripe',
        'scenario' => 'typo',
        'steps' => [$step],
    ]);
})->with([
    'hyphen instead of underscore' => '{{sub-1}}',
    'kind that is not a provider id' => '{{subscription_1}}',
    'capitalised kind' => '{{Sub_1}}',
    'offset in words' => '{{t+14days}}',
    'offset out of order' => '{{t+1h1d}}',
    'empty' => '{{}}',
])->with(['event', 'api response'])
    ->throws(InvalidFixture::class, 'is not a placeholder this version of cashier-dunning understands');
