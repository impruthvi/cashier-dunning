<?php

namespace Impruthvi\CashierDunning\Simulation\Exceptions;

use RuntimeException;

class SimulationFailed extends RuntimeException
{
    public static function alreadyRunning(): self
    {
        return new self(
            'A simulation is already running. The environment swaps global state '.
            "— Stripe's HTTP transport, Cashier's credentials, the clock — and a ".
            'nested run would restore the wrong values on the way out.'
        );
    }

    public static function noBillable(): self
    {
        return new self(
            'Cannot start a simulation without a billable. Register one:'."\n\n".
            '    CashierDunning::createBillableUsing(fn () => User::factory()->create());'."\n\n".
            'A replay drives your application through a billing lifecycle, so it '.
            'needs the model that lifecycle belongs to.'
        );
    }

    public static function billableFactoryReturnedNonObject(string $type): self
    {
        return new self(
            "The registered billable factory returned {$type}. It must return the ".
            'model the subscription belongs to.'
        );
    }

    public static function afterCommitCallbacksCannotBeObserved(int $count): self
    {
        $callbacks = $count === 1 ? 'callback' : 'callbacks';

        return new self(
            'The replay scheduled after-commit work that could not be observed '.
            "({$count} {$callbacks}). ".
            'Cashier Dunning rolls back its database transaction, so Laravel '.
            'will discard that work without dispatching it. No success verdict '.
            'was reported because its side effects could not be verified.'
        );
    }
}
