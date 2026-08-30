<?php

namespace Impruthvi\CashierDunning;

use Closure;
use Impruthvi\CashierDunning\Contracts\EntitlementResolver;
use Impruthvi\CashierDunning\Entitlements\CallbackResolver;

/**
 * Registration surface for the things an application teaches the package.
 *
 * Entitlements are registered in code rather than in the published config file
 * because the natural way to express them is a closure, and `php artisan
 * config:cache` cannot serialise a closure. Putting the callback here keeps
 * cached-config deployments working. The config file still accepts a
 * class-string for applications that prefer a dedicated resolver class.
 */
class CashierDunning
{
    private static ?Closure $entitlementResolver = null;

    /**
     * @param  Closure(object): array<string, scalar|null>  $callback
     */
    public static function resolveEntitlementsUsing(Closure $callback): void
    {
        self::$entitlementResolver = $callback;
    }

    public static function entitlementResolver(): ?EntitlementResolver
    {
        return self::$entitlementResolver === null
            ? null
            : new CallbackResolver(self::$entitlementResolver);
    }

    /**
     * Registered callbacks are process-global, which is what makes them work in
     * a service provider and what makes them leak between tests.
     */
    public static function flush(): void
    {
        self::$entitlementResolver = null;
    }
}
