<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Support\WebhookEvent;

/**
 * Decides which fields may appear in a published fixture.
 *
 * Fixtures are committed, shipped inside the package, and accepted from
 * strangers by pull request. Provider payloads carry customer emails, names,
 * billing addresses, tax ids, product and price nicknames, and arbitrary
 * metadata. None of that belongs in a public corpus.
 *
 * This is an allowlist rather than a denylist on purpose: it fails closed. A
 * field the provider adds next year is excluded until someone deliberately
 * permits it, instead of leaking until someone notices. It lives in code rather
 * than config so an application cannot weaken it.
 *
 * Two modes:
 *   apply(), applyToObject()  - used on record. Unlisted fields are dropped,
 *                               not masked.
 *   disallowedPaths(), disallowedPathsInObject() - used on save and load. Unlisted
 *                               fields refuse the fixture, because their
 *                               presence means it did not come from apply().
 *   disallowedRequestParameters() - used on save and load, for the request parameters
 *                               an API exchange matches on. The recorder
 *                               always writes an empty match body, so there is
 *                               no drop-on-record mode; a hand-authored match
 *                               body is judged here.
 */
final class Allowlist
{
    /**
     * Paths permitted on every event, relative to the event root.
     *
     * @var list<string>
     */
    private const COMMON = [
        'id',
        'type',
        'created',
        'data.object.id',
        'data.object.object',
    ];

    /**
     * Per-event-type additions. `*` matches any single array index or key.
     *
     * @var array<string, list<string>>
     */
    private const BY_TYPE = [
        'customer.subscription.created' => self::SUBSCRIPTION,
        'customer.subscription.updated' => self::SUBSCRIPTION,
        'customer.subscription.deleted' => self::SUBSCRIPTION,
        'customer.subscription.trial_will_end' => self::SUBSCRIPTION,
        'invoice.created' => self::INVOICE,
        'invoice.paid' => self::INVOICE,
        'invoice.payment_failed' => self::INVOICE,
        'invoice.payment_succeeded' => self::INVOICE,
        'invoice.finalized' => self::INVOICE,
    ];

    /**
     * Rules for a bare provider object, such as a recorded API response, keyed
     * by the object's own `object` field. An object of a kind not listed here
     * keeps nothing beyond the common fields: the allowlist fails closed.
     *
     * @var array<string, list<string>>
     */
    private const BY_OBJECT = [
        'subscription' => self::SUBSCRIPTION,
        'invoice' => self::INVOICE,
        'subscription_item' => self::SUBSCRIPTION_ITEM,
    ];

    /**
     * Request parameters an API exchange may match on, for every endpoint. What
     * makes a parameter unsafe is its name, not the endpoint it is sent to, so
     * one flat list. Only keys are judged, never values. Parameters carrying
     * customer data (email, name, address, phone, description, metadata,
     * shipping, tax_id_data, ...) are never listed.
     *
     * @var list<string>
     */
    private const REQUEST_PARAMETERS = [
        'customer',
        'subscription',
        'price',
        'quantity',
        'items.*.id',
        'items.*.price',
        'items.*.quantity',
        'items.*.deleted',
        'cancel_at_period_end',
        'proration_behavior',
        'payment_behavior',
        'trial_end',
        'billing_cycle_anchor',
        'expand.*',
    ];

    /**
     * Stripe's error body, such as the 402 of a card decline. It has no
     * `object` field of its own, so it is recognised by having none and
     * carrying `error` instead. `message` is Stripe's text, not the customer's.
     *
     * @var list<string>
     */
    private const ERROR = [
        'data.object.error.type',
        'data.object.error.code',
        'data.object.error.decline_code',
        'data.object.error.message',
        'data.object.error.param',
    ];

    /** @var list<string> */
    private const SUBSCRIPTION = [
        'data.object.status',
        'data.object.customer',
        'data.object.current_period_start',
        'data.object.current_period_end',
        'data.object.trial_start',
        'data.object.trial_end',
        'data.object.cancel_at_period_end',
        'data.object.canceled_at',
        'data.object.ended_at',
        'data.object.items.data.*.id',
        'data.object.items.data.*.quantity',
        'data.object.items.data.*.price.id',
        // Cashier writes stripe_product on every subscription item, so a fixture
        // without it cannot be replayed at all. A product id names a plan; it is
        // not customer data.
        'data.object.items.data.*.price.product',
        'data.object.items.data.*.price.recurring.interval',
    ];

    /**
     * The fields a subscription already carries for each of its items, plus
     * the item's own period and the subscription it belongs to. Cashier reads
     * the billing period from the item, so it is plan data, not customer data.
     *
     * @var list<string>
     */
    private const SUBSCRIPTION_ITEM = [
        'data.object.subscription',
        'data.object.quantity',
        'data.object.current_period_start',
        'data.object.current_period_end',
        'data.object.price.id',
        'data.object.price.object',
        'data.object.price.product',
        'data.object.price.recurring.interval',
    ];

    /** @var list<string> */
    private const INVOICE = [
        'data.object.status',
        'data.object.customer',
        'data.object.subscription',
        'data.object.amount_due',
        'data.object.amount_paid',
        'data.object.currency',
        'data.object.attempt_count',
        'data.object.next_payment_attempt',
        'data.object.billing_reason',
    ];

    /**
     * Drop every field the allowlist does not permit.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function apply(array $event): array
    {
        $permitted = $this->permittedFor(WebhookEvent::type($event) ?? '');

        return $this->filter($event, $permitted);
    }

    /**
     * Drop unpermitted fields from a bare provider object, such as a recorded
     * API response, using the rules for the object's own kind.
     *
     * A recorded API response and the webhook describing the same subscription
     * are the same data arriving by different routes, so they are redacted by
     * exactly the same list. Two lists would drift, and the one that drifted
     * would be the one nobody was looking at. Loading a fixture judges the
     * response by the same lookup, in disallowedPathsInObject().
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    public function applyToObject(array $object): array
    {
        $filtered = $this->filter(['data' => ['object' => $object]], $this->permittedForObject($object));

        return WebhookEvent::object($filtered);
    }

    /**
     * Fields in a bare provider object that the allowlist does not permit,
     * judged by the rules for the object's own kind.
     *
     * @param  array<string, mixed>  $object
     * @return list<string> paths relative to the object
     */
    public function disallowedPathsInObject(array $object): array
    {
        $paths = $this->collect(['data' => ['object' => $object]], $this->permittedForObject($object));

        return array_map(static fn (string $path): string => substr($path, strlen('data.object.')), $paths);
    }

    /**
     * Request parameters in an API exchange's match body that the allowlist
     * does not permit.
     *
     * @param  array<array-key, mixed>  $matchBody
     * @return list<string> parameter paths, such as `metadata` or `items.0.name`
     */
    public function disallowedRequestParameters(array $matchBody): array
    {
        return $this->collect($matchBody, self::REQUEST_PARAMETERS);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>|null  $permitted
     * @return list<string>
     */
    public function disallowedPaths(array $event, ?array $permitted = null): array
    {
        $permitted ??= $this->permittedFor(WebhookEvent::type($event) ?? '');

        return $this->collect($event, $permitted);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return list<string>
     */
    private function permittedForObject(array $object): array
    {
        if (self::isErrorBody($object)) {
            return self::ERROR;
        }

        $kind = $object['object'] ?? null;

        return array_merge(
            ['data.object.id', 'data.object.object'],
            is_string($kind) ? (self::BY_OBJECT[$kind] ?? []) : [],
        );
    }

    /**
     * Whether a bare provider object is Stripe's error body: an `error` and no
     * `object` of its own.
     *
     * @param  array<array-key, mixed>  $object
     */
    public static function isErrorBody(array $object): bool
    {
        return ! array_key_exists('object', $object) && array_key_exists('error', $object);
    }

    /** @return list<string> */
    private function permittedFor(string $type): array
    {
        return array_merge(self::COMMON, self::BY_TYPE[$type] ?? []);
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $permitted
     * @return array<array-key, mixed>
     */
    private function filter(array $node, array $permitted, string $prefix = ''): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                if (! $this->isPrefixOfAny($path, $permitted)) {
                    continue;
                }

                $out[$key] = $this->filter($value, $permitted, $path);

                continue;
            }

            if ($this->isPermitted($path, $permitted)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $permitted
     * @return list<string>
     */
    private function collect(array $node, array $permitted, string $prefix = ''): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                if (! $this->isPrefixOfAny($path, $permitted)) {
                    $found[] = $path;

                    continue;
                }

                $found = array_merge($found, $this->collect($value, $permitted, $path));

                continue;
            }

            if (! $this->isPermitted($path, $permitted)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /** @param list<string> $permitted */
    private function isPermitted(string $path, array $permitted): bool
    {
        foreach ($permitted as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A container is kept only if some permitted path lives beneath it.
     *
     * @param  list<string>  $permitted
     */
    private function isPrefixOfAny(string $path, array $permitted): bool
    {
        foreach ($permitted as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return true;
            }

            $segments = explode('.', $pattern);
            $candidate = explode('.', $path);

            if (count($candidate) >= count($segments)) {
                continue;
            }

            if ($this->pathMatches($path, implode('.', array_slice($segments, 0, count($candidate))))) {
                return true;
            }
        }

        return false;
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        $pathParts = explode('.', $path);
        $patternParts = explode('.', $pattern);

        if (count($pathParts) !== count($patternParts)) {
            return false;
        }

        foreach ($patternParts as $index => $segment) {
            if ($segment !== '*' && $segment !== $pathParts[$index]) {
                return false;
            }
        }

        return true;
    }
}
