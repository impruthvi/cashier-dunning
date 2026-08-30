<?php

namespace Impruthvi\CashierDunning\Fixtures;

use stdClass;

/**
 * PHP cannot tell an empty list from an empty map: both are `[]`, and both
 * encode to `[]`. A fixture that recorded `"body": {}` would therefore come back
 * as `"body": []`, and every re-record would diff on it.
 *
 * Keys whose type is fixed by the format are cast here on the way out. Recorded
 * provider payloads cannot be cast this way — nothing knows their shape — so the
 * normalizer drops empty containers from them instead.
 */
final class Json
{
    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    public static function object(array $value): array|stdClass
    {
        return $value === [] ? new stdClass : $value;
    }

    /**
     * As object(), applied at every depth. Only for subtrees the format defines
     * as maps all the way down, such as provenance.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>|stdClass
     */
    public static function objectDeep(array $value): array|stdClass
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::objectDeep($item);
            }
        }

        return self::object($value);
    }
}
