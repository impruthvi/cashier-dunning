<?php

use Impruthvi\CashierDunning\Fixtures\Allowlist;
use Impruthvi\CashierDunning\Fixtures\Exceptions\DisallowedField;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;

/** @return array<string, mixed> */
function subscriptionEventWithPii(): array
{
    return [
        'id' => 'evt_1',
        'type' => 'customer.subscription.created',
        'created' => 1767225600,
        'data' => [
            'object' => [
                'id' => 'sub_1',
                'object' => 'subscription',
                'status' => 'trialing',
                'customer' => 'cus_1',
                'customer_email' => 'jenny.rosen@example.com',
                'metadata' => ['internal_account_ref' => 'ACME-4471'],
                'items' => [
                    'data' => [[
                        'id' => 'si_1',
                        'quantity' => 1,
                        'price' => [
                            'id' => 'price_1',
                            'nickname' => 'Acme Enterprise Negotiated Rate',
                            'recurring' => ['interval' => 'month'],
                        ],
                    ]],
                ],
            ],
        ],
    ];
}

it('drops fields that are not on the allowlist', function () {
    $filtered = (new Allowlist)->apply(subscriptionEventWithPii());

    expect($filtered['data']['object'])->not->toHaveKey('customer_email')
        ->and($filtered['data']['object'])->not->toHaveKey('metadata')
        ->and($filtered['data']['object']['items']['data'][0]['price'])->not->toHaveKey('nickname');
});

it('keeps the fields the replay actually needs', function () {
    $filtered = (new Allowlist)->apply(subscriptionEventWithPii());

    expect($filtered['id'])->toBe('evt_1')
        ->and($filtered['type'])->toBe('customer.subscription.created')
        ->and($filtered['data']['object']['status'])->toBe('trialing')
        ->and($filtered['data']['object']['items']['data'][0]['price']['id'])->toBe('price_1')
        ->and($filtered['data']['object']['items']['data'][0]['price']['recurring']['interval'])->toBe('month');
});

it('fails closed on a field the provider adds later', function () {
    // An allowlist means a new provider field is excluded until someone
    // deliberately permits it, rather than leaking until someone notices.
    $event = subscriptionEventWithPii();
    $event['data']['object']['some_field_invented_in_2027'] = 'sensitive';

    expect((new Allowlist)->apply($event)['data']['object'])
        ->not->toHaveKey('some_field_invented_in_2027');
});

it('reports every disallowed path rather than only the first', function () {
    $paths = (new Allowlist)->disallowedPaths(subscriptionEventWithPii());

    expect($paths)->toContain('data.object.customer_email')
        ->and($paths)->toContain('data.object.metadata')
        ->and($paths)->toContain('data.object.items.data.0.price.nickname');
});

it('throws when asserting against a fixture that was not filtered', function () {
    // assert() is the validate path: a fixture carrying unlisted fields did not
    // come from apply(), so it has not been through redaction.
    (new Allowlist)->assert(subscriptionEventWithPii());
})->throws(DisallowedField::class, 'customer_email');

it('accepts an event that has already been filtered', function () {
    $allowlist = new Allowlist;

    $allowlist->assert($allowlist->apply(subscriptionEventWithPii()));
})->throwsNoExceptions();

it('accepts every event in the shipped reference fixture', function () {
    // The corpus is published. If this ever fails, something unpublishable is
    // about to be committed.
    $allowlist = new Allowlist;
    $fixture = FixtureFile::read(__DIR__.'/../../fixtures/stripe/trial-dunning-cancel-reactivate.json');

    foreach ($fixture->steps as $step) {
        foreach ($step->events as $event) {
            $allowlist->assert($event);
        }
    }
})->throwsNoExceptions();
