<?php

namespace Impruthvi\CashierDunning\Record;

/**
 * What one recording has created so far in the Stripe account.
 *
 * Mutable and deliberately small. A scenario's later steps need the ids its
 * earlier steps produced — you cannot replace a payment method on a customer
 * that does not exist yet — and threading them through as arguments would put
 * the shape of one scenario into the signature of every method.
 */
final class RecordingState
{
    public ?string $customerId = null;

    public ?string $subscriptionId = null;

    /** @var array<string, true> event types the scenario never asked about */
    public array $undeclared = [];
}
