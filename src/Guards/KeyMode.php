<?php

namespace Impruthvi\CashierDunning\Guards;

/**
 * What a Stripe API key is, judged from its prefix alone.
 *
 * Classified offline and without ever contacting Stripe, because the whole
 * point is to decide whether contacting Stripe is safe.
 */
enum KeyMode: string
{
    case Test = 'test';
    case Live = 'live';
    case Missing = 'missing';
    case Unrecognised = 'unrecognised';

    public function canRecord(): bool
    {
        return $this === self::Test;
    }

    public function describe(): string
    {
        return match ($this) {
            self::Test => 'test mode',
            self::Live => 'LIVE mode',
            self::Missing => 'no key configured',
            self::Unrecognised => 'unrecognised key format',
        };
    }
}
