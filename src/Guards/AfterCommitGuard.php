<?php

namespace Impruthvi\CashierDunning\Guards;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;

/**
 * Finds callbacks that Laravel will discard with a simulated transaction.
 *
 * Queue jobs configured for after-commit do not emit JobQueued until the root
 * transaction commits. A replay always rolls back, so the side-effect ledger
 * cannot observe those jobs. Reporting success in that situation would claim
 * the application behaved identically without seeing what it tried to do.
 */
final readonly class AfterCommitGuard
{
    public function __construct(private DatabaseTransactionsManager $transactions) {}

    public function callbacksDiscardedByRollback(Connection $connection, int $entryLevel): int
    {
        return $this->records()
            ->filter(
                static fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection->getName()
                    && $transaction->level > $entryLevel
            )
            ->sum(
                static fn (DatabaseTransactionRecord $transaction): int => count($transaction->getCallbacks())
            );
    }

    /** @return Collection<int, DatabaseTransactionRecord> */
    private function records(): Collection
    {
        return $this->transactions->getPendingTransactions()
            ->concat($this->transactions->getCommittedTransactions());
    }
}
