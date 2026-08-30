<?php

use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\Exceptions\InvalidResolver;
use Impruthvi\CashierDunning\Entitlements\NullResolver;

afterEach(fn () => CashierDunning::flush());

it('resolves to a null resolver when the application registered nothing', function () {
    // Running a scenario before wiring entitlements must work. Subscription
    // status and webhook ordering are worth watching on their own.
    expect(app(EntitlementResolver::class))->toBeInstanceOf(NullResolver::class)
        ->and(app(EntitlementResolver::class)->resolve(new stdClass))->toBe([]);
});

it('prefers a callback registered in code over the config file', function () {
    // A closure cannot live in a cached config file, so code registration is
    // the documented path and has to win.
    config()->set('cashier-dunning.entitlements.resolver', ConfiguredResolver::class);
    CashierDunning::resolveEntitlementsUsing(fn () => ['teams' => true]);

    expect(app(EntitlementResolver::class)->resolve(new stdClass))->toBe(['teams' => true]);
});

it('builds a configured resolver class through the container', function () {
    config()->set('cashier-dunning.entitlements.resolver', ConfiguredResolver::class);

    expect(app(EntitlementResolver::class)->resolve(new stdClass))->toBe(['api' => false]);
});

it('refuses a configured class that does not implement the contract', function () {
    config()->set('cashier-dunning.entitlements.resolver', stdClass::class);

    app(EntitlementResolver::class);
})->throws(InvalidResolver::class, 'does not implement');

it('rejects a callback that returns something other than a snapshot', function () {
    CashierDunning::resolveEntitlementsUsing(fn () => 'yes');

    app(EntitlementResolver::class)->resolve(new stdClass);
})->throws(InvalidResolver::class, 'returned string');

it('rejects a callback that returns a nested value', function () {
    // Entitlement values are written into fixtures and diffed. A nested array
    // would pass here and fail much later as an unreadable diff.
    CashierDunning::resolveEntitlementsUsing(fn () => ['plan' => ['name' => 'pro']]);

    app(EntitlementResolver::class)->resolve(new stdClass);
})->throws(InvalidResolver::class, 'feature [plan]');

it('passes the billable through to the callback', function () {
    $billable = new stdClass;
    $billable->id = 7;

    CashierDunning::resolveEntitlementsUsing(fn (object $b) => ['seat' => $b->id]);

    expect(app(EntitlementResolver::class)->resolve($billable))->toBe(['seat' => 7]);
});

final class ConfiguredResolver implements EntitlementResolver
{
    public function resolve(object $billable): array
    {
        return ['api' => false];
    }
}
