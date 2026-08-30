<?php

namespace Impruthvi\CashierDunning\Guards\Exceptions;

use Impruthvi\CashierDunning\Guards\KeyMode;
use RuntimeException;

class UnsafeKey extends RuntimeException
{
    public static function forRecording(KeyMode $mode): self
    {
        return new self(match ($mode) {
            KeyMode::Live => 'Refusing to record: the configured Stripe key is a LIVE key. '.
                'Recording creates customers, subscriptions and invoices, and advances a '.
                'test clock through months of billing. Against a live key that means real '.
                'charges to real cards. Set a test key (sk_test_… or rk_test_…) and try again.',

            KeyMode::Missing => 'Refusing to record: no Stripe key is configured. Set a test '.
                'key in STRIPE_SECRET. Replay does not need one; recording does.',

            KeyMode::Unrecognised => 'Refusing to record: the configured Stripe key is not in a '.
                'recognised format. This guard only permits keys it can positively identify as '.
                'test keys, because the cost of guessing wrong is charging real customers.',

            KeyMode::Test => 'Refusing to record.',
        });
    }
}
