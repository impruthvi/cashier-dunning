<?php

namespace Impruthvi\CashierDunning\Simulation;

use Random\RandomException;

/**
 * Throwaway Stripe credentials that exist only for the length of one
 * simulation.
 *
 * Cashier refuses to operate without a secret key, and its webhook route
 * verifies signatures only when a webhook secret is configured. A replay that
 * skipped either would be proving less than a live run: no key means no Cashier,
 * and no webhook secret means signature verification is silently switched off,
 * which is exactly the code most worth exercising.
 *
 * So the environment supplies both — invented, per run, never persisted. They
 * are shaped like real Stripe credentials because Cashier and the guard both
 * inspect the prefix, and they are worthless because no request made with them
 * ever leaves the process.
 *
 * The useful consequence is that a replay physically cannot use the
 * application's real key: for the duration of the run, the real one is not in
 * configuration to be read.
 */
final readonly class EphemeralCredentials
{
    private function __construct(
        public string $secret,
        public string $webhookSecret,
    ) {}

    /** @throws RandomException */
    public static function generate(): self
    {
        return new self(
            secret: 'sk_test_replay'.bin2hex(random_bytes(16)),
            webhookSecret: 'whsec_replay'.bin2hex(random_bytes(16)),
        );
    }
}
