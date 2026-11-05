<?php

namespace Impruthvi\CashierDunning\Support;

use Impruthvi\CashierDunning\Fixtures\DecodedObject;

/**
 * Reads the fields everything needs off a webhook event payload.
 *
 * An event is an `array<string, mixed>` from Stripe or a decoded fixture, and a
 * dozen places read its type and id. These helpers are those reads in one
 * place, returning null rather than casting when a field is absent or not a
 * string, so each caller still picks its own fallback. A fixture event is
 * already checked to carry both as strings when it is loaded.
 *
 * Deliberately not an event value object: the payload is passed through to
 * the application untouched, and wrapping it everywhere is a bigger change
 * than reading two fields justifies.
 */
final class WebhookEvent
{
    /** @param array<string, mixed> $event */
    public static function type(array $event): ?string
    {
        return is_string($event['type'] ?? null) ? $event['type'] : null;
    }

    /** @param array<string, mixed> $event */
    public static function id(array $event): ?string
    {
        return is_string($event['id'] ?? null) ? $event['id'] : null;
    }

    /**
     * The provider object the event carries, at `data.object`; empty when it
     * carries none.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public static function object(array $event): array
    {
        $data = $event['data'] ?? null;
        $object = is_array($data) ? ($data['object'] ?? null) : null;

        return DecodedObject::isObject($object) ? $object : [];
    }
}
