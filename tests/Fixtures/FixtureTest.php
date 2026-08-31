<?php

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Fixtures\FixtureFile;
use Impruthvi\CashierDunning\Fixtures\Provider;

function referenceFixturePath(): string
{
    return __DIR__.'/../../fixtures/stripe/trial-dunning-cancel-reactivate.json';
}

/** @return array<string, mixed> */
function minimalFixtureArray(array $overrides = []): array
{
    return array_replace([
        'format_version' => 1,
        'provider' => 'stripe',
        'scenario' => 'minimal',
        'provenance' => ['synthetic' => true],
        'manifest' => ['required_events' => [], 'optional_events' => []],
        'steps' => [[
            'advance_to' => '0d',
            'label' => 'start',
            'events' => [['id' => '{{evt_1}}', 'type' => 'customer.subscription.created']],
        ]],
    ], $overrides);
}

it('reads the reference dunning fixture', function () {
    $fixture = FixtureFile::read(referenceFixturePath());

    expect($fixture->provider)->toBe(Provider::Stripe)
        ->and($fixture->scenario)->toBe('trial-dunning-cancel-reactivate')
        ->and($fixture->steps)->toHaveCount(7)
        ->and($fixture->provenance['synthetic'])->toBeTrue();
});

/** @return list<string> */
function shippedFixturePaths(): array
{
    return glob(__DIR__.'/../../fixtures/*/*.json') ?: [];
}

it('round-trips every shipped fixture byte for byte', function (string $path) {
    // The corpus grows by pull request. If encoding is not stable, every
    // re-record diffs on formatting and nobody can review a contribution.
    expect(FixtureFile::encode(FixtureFile::read($path)))->toBe(file_get_contents($path));
})->with(shippedFixturePaths());

it('stores every shipped fixture with LF line endings', function (string $path) {
    // The format declares LF. Git converts line endings on checkout unless told
    // not to, so on Windows a CRLF fixture makes the round-trip test above
    // report that the entire file changed. This names the real problem instead.
    expect(file_get_contents($path))->not->toContain("\r");
})->with(shippedFixturePaths());

it('ships at least one fixture to round-trip', function () {
    // Guards the test above: a glob that matches nothing passes silently.
    expect(shippedFixturePaths())->not->toBeEmpty();
});

it('encodes empty maps as objects rather than arrays', function () {
    // PHP represents both `{}` and `[]` as an empty array. Without an explicit
    // cast, a recorded `"body": {}` comes back as `"body": []` on the next write.
    $encoded = FixtureFile::encode(FixtureFile::decode(json_encode(minimalFixtureArray([
        'steps' => [[
            'advance_to' => '0d',
            'label' => 'start',
            'events' => [['id' => '{{evt_1}}', 'type' => 'customer.subscription.created']],
            'api_responses' => [[
                'match' => ['method' => 'GET', 'path' => '/v1/subscriptions/{{sub_1}}', 'body' => []],
                'response' => ['status' => 200, 'body' => ['id' => '{{sub_1}}']],
            ]],
            'entitlements' => [],
        ]],
    ]))));

    expect($encoded)->toContain('"body": {}')
        ->and($encoded)->toContain('"entitlements": {}')
        ->and($encoded)->toContain('"api_responses": [');
});

it('refuses a fixture with no steps', function () {
    // A fixture with zero steps replays green while asserting nothing, which is
    // the exact failure this package exists to prevent.
    FixtureFile::decode(json_encode(minimalFixtureArray(['steps' => []])));
})->throws(InvalidFixture::class, 'contains no steps');

it('refuses an unsupported format version', function () {
    FixtureFile::decode(json_encode(minimalFixtureArray(['format_version' => 99])));
})->throws(InvalidFixture::class, 'format version 99');

it('refuses an unknown provider', function () {
    FixtureFile::decode(json_encode(minimalFixtureArray(['provider' => 'paddle'])));
})->throws(InvalidFixture::class);

it('refuses malformed json', function () {
    FixtureFile::decode('{not json');
})->throws(InvalidFixture::class, 'not valid JSON');

it('names the missing key when a top-level key is absent', function () {
    $data = minimalFixtureArray();
    unset($data['scenario']);

    FixtureFile::decode(json_encode($data));
})->throws(InvalidFixture::class, '[scenario]');

it('reports required events that the recording never produced', function () {
    $fixture = FixtureFile::decode(json_encode(minimalFixtureArray([
        'manifest' => [
            'required_events' => ['customer.subscription.created', 'invoice.payment_failed'],
            'optional_events' => [],
        ],
    ])));

    expect($fixture->satisfiesManifest())->toBeFalse()
        ->and($fixture->missingRequiredEvents())->toBe(['invoice.payment_failed']);
});

it('considers the reference fixture to satisfy its own manifest', function () {
    expect(FixtureFile::read(referenceFixturePath())->satisfiesManifest())->toBeTrue();
});

it('marks single-event steps as not shuffleable', function () {
    $fixture = FixtureFile::read(referenceFixturePath());

    // Shuffling one event is a no-op; reporting it as a passing chaos run would
    // be coverage theatre.
    expect($fixture->steps[1]->isShuffleable())->toBeFalse()
        ->and($fixture->steps[0]->isShuffleable())->toBeTrue();
});

it('exposes format version as a constant so the loader can gate on it', function () {
    expect(Fixture::FORMAT_VERSION)->toBe(1);
});
