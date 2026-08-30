<?php

namespace Impruthvi\CashierDunning\Fixtures;

use Impruthvi\CashierDunning\Fixtures\Exceptions\InvalidFixture;

/**
 * The billing provider a fixture was recorded from.
 *
 * This exists as an enum rather than a bare string so that adding a second
 * provider later is a change to this file plus a namespaced allowlist, not a
 * migration across every fixture in the corpus. Fixtures are the one-way door
 * in this package: once contributed fixtures exist, the format is effectively
 * frozen.
 */
enum Provider: string
{
    case Stripe = 'stripe';

    public static function fromFixture(mixed $value): self
    {
        if (! is_string($value)) {
            throw InvalidFixture::missingKey('provider');
        }

        return self::tryFrom($value)
            ?? throw InvalidFixture::unknownProvider($value);
    }

    /**
     * Namespace for provider-specific concerns: allowlists, id prefixes,
     * timestamp keys. Keeps stripe-shaped assumptions from leaking into the
     * format itself.
     */
    public function namespace(): string
    {
        return $this->value;
    }
}
