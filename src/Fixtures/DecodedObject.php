<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Closure;
use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * One JSON object from a decoded fixture, read a key at a time.
 *
 * A fixture can be written by hand, so its shape is checked rather than
 * trusted. A key that is present with the wrong type is refused, not cast:
 * a cast would replay `"status": "abc"` as status 0 and `"body": "x"` as the
 * match body `["x"]`. Numeric strings are refused too. A key that is absent
 * takes the default the caller gives, so an optional key stays optional.
 *
 * Each refusal names the key by its path from where the caller started
 * reading, such as `[response.status]`, and the caller's `$refuse` says which
 * step and exchange it was in.
 *
 * @internal
 */
final readonly class DecodedObject
{
    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(string): InvalidFixture  $refuse
     */
    private function __construct(
        public array $data,
        private Closure $refuse,
        private string $path,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  Closure(string): InvalidFixture  $refuse  builds the exception from a reason such as "[match] must be an object"
     */
    public static function of(array $data, Closure $refuse): self
    {
        return new self($data, $refuse, '');
    }

    /**
     * A JSON object decodes to an array with string keys, and `{}` to `[]`.
     * A list, even a one-element one, is not an object.
     *
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }

    public function string(string $key, ?string $default = null): string
    {
        $value = $this->value($key, $default);

        return is_string($value) ? $value : throw $this->wrongType($key, 'a string');
    }

    public function int(string $key, ?int $default = null): int
    {
        $value = $this->value($key, $default);

        return is_int($value) ? $value : throw $this->wrongType($key, 'an integer');
    }

    /** @return array<string, mixed> */
    public function object(string $key): array
    {
        return $this->nested($key)->data;
    }

    /** The object under `$key`, read with its path prefixed; empty when absent. */
    public function nested(string $key): self
    {
        $value = $this->value($key, []);

        if (! self::isObject($value)) {
            throw $this->wrongType($key, 'an object');
        }

        return new self($value, $this->refuse, $this->path($key).'.');
    }

    /**
     * Each entry of the list under `$key`, as an object; empty when absent.
     *
     * @return list<self>
     */
    public function objects(string $key): array
    {
        $objects = [];

        foreach ($this->list($key) as $index => $value) {
            if (! self::isObject($value)) {
                throw ($this->refuse)("[{$this->path($key)}[{$index}]] must be an object");
            }

            $objects[] = new self($value, $this->refuse, "{$this->path($key)}[{$index}].");
        }

        return $objects;
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $strings = [];

        foreach ($this->list($key) as $value) {
            $strings[] = is_string($value) ? $value : throw $this->wrongType($key, 'a list of strings');
        }

        return $strings;
    }

    /**
     * An object whose values are all scalars or null, such as an entitlement
     * snapshot; empty when absent.
     *
     * @return array<string, scalar|null>
     */
    public function scalars(string $key): array
    {
        $scalars = [];

        foreach ($this->object($key) as $name => $value) {
            $scalars[$name] = is_scalar($value) || $value === null
                ? $value
                : throw $this->wrongType("{$key}.{$name}", 'a scalar or null');
        }

        return $scalars;
    }

    /**
     * The list under `$key`, entries unchecked; empty when absent.
     *
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->value($key, []);

        return is_array($value) && array_is_list($value) ? $value : throw $this->wrongType($key, 'a list');
    }

    private function value(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->data)) {
            return $this->data[$key];
        }

        return $default ?? throw ($this->refuse)("missing key [{$this->path($key)}]");
    }

    private function wrongType(string $key, string $expected): InvalidFixture
    {
        return ($this->refuse)("[{$this->path($key)}] must be {$expected}");
    }

    private function path(string $key): string
    {
        return $this->path.$key;
    }
}
