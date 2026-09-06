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

    public static function billableStripeIdDoesNotMatch(
        string $type,
        string $expectedStripeId,
        ?string $actualStripeId,
    ): self {
        $actual = $actualStripeId === null
            ? 'no stripe_id'
            : "stripe_id [{$actualStripeId}]";

        return new self(
            "The billable factory returned [{$type}] with {$actual}, but fixture ".
            "webhooks target stripe_id [{$expectedStripeId}]. Create and save the ".
            'billable with that replay ID in CashierDunning::createBillableUsing().'
        );
    }

    public static function billableResolvedToDifferentRecord(string $type, string $stripeId): self
    {
        return new self(
            "The billable factory created [{$type}] with stripe_id [{$stripeId}], ".
            'but Cashier::findBillable() returned a different record. Ensure the '.
            'replay stripe_id is unique and remove rows left by an earlier replay.'
        );
    }

    public static function replayTransactionWasEnded(): self
    {
        return new self(
            'Application code committed or rolled back the transaction this replay '.
            'opened, so the replay could not undo its own writes and they are now '.
            'permanent. Remove the DB::commit() or DB::rollBack() from the code your '.
            'webhooks reach, or move it onto its own connection.'
        );
    }

    public static function billableCouldNotBeResolved(string $type, string $stripeId): self
    {
        return new self(
            "The billable factory created [{$type}] with stripe_id [{$stripeId}], ".
            'but Cashier::findBillable() found no record with that id. Check that '.
            'Cashier::useCustomerModel() points at the model your factory creates, '.
            'and that both use the same database connection.'
        );
    }

    public static function billableIsNotEloquent(string $type): self
    {
        return new self(
            "The billable factory returned [{$type}], which is not an Eloquent model. ".
            'Cashier resolves billables through the query builder, so a replay cannot '.
            'confirm the record it is about to drive webhooks at.'
        );
    }

    public static function billableIdIsNotUnique(int $count, string $stripeId): self
    {
        return new self(
            "{$count} customer records share stripe_id [{$stripeId}], so a replay ".
            'cannot tell which one its webhooks are addressed to. Rows left behind '.
            'by a v0.1.0 replay are the usual cause — delete them and run again.'
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
