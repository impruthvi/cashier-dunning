<?php

namespace Impruthvi\CashierDunning\Simulation;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Chaos\SideEffectLedger;
use Impruthvi\CashierDunning\Fixtures\Fixture;
use Impruthvi\CashierDunning\Guards\AfterCommitGuard;
use Impruthvi\CashierDunning\Guards\OutboundGuard;
use Impruthvi\CashierDunning\Replay\FixtureHttpClient;
use Impruthvi\CashierDunning\Replay\Placeholders;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Laravel\Cashier\Cashier;
use Stripe\ApiRequestor;
use Throwable;

/**
 * Sets up the world a replay runs in, and — the part that matters — puts it
 * back.
 *
 * Database writes on the billable's connection and the default connection
 * happen inside transactions the environment opens before the billable
 * factory runs, so the factory's own INSERT is isolated too, and each is
 * always rolled back to the caller's entry level. When the two are the same
 * connection, one transaction is opened. A transaction the caller already
 * holds, such as a test using `RefreshDatabase`, is left exactly as it was.
 * Writes to any other connection are not isolated (ADR-0001).
 *
 * Three pieces of global state have to move for a replay to work without a
 * Stripe account:
 *
 *   1. Cashier's credentials, replaced with ephemeral ones so Cashier will
 *      operate and so the application's real key is not even readable during
 *      the run.
 *   2. Stripe's HTTP transport. The SDK does not use Laravel's HTTP client, so
 *      `Http::fake()` cannot intercept it; `ApiRequestor::setHttpClient()` is
 *      the only seam, and it is a global static.
 *   3. The clock, so that a subscription whose trial ends in fourteen days can
 *      be observed ending.
 *
 * And side effects are recorded, and their delivery refused, for the whole
 * run. A replay drives the application's real dunning listeners, and those
 * exist to email customers; every attempt lands in a fresh ledger the context
 * reads back.
 *
 * All of these are process-global. Leaking any of them out of a simulation would
 * corrupt every test that runs afterwards — and the corruption would surface
 * somewhere else entirely, as a test that fails only when run in a certain
 * order. So restoration happens in `finally`, unconditionally, including when
 * the callback throws.
 *
 * @internal
 */
final class SimulationEnvironment
{
    private bool $running = false;

    private readonly AfterCommitGuard $afterCommitGuard;

    public function __construct(
        private readonly Repository $config,
        private readonly OutboundGuard $outboundGuard,
        private readonly DatabaseManager $databaseManager,
        DatabaseTransactionsManager $transactions,
    ) {
        $this->afterCommitGuard = new AfterCommitGuard($transactions);
    }

    /**
     * @template T
     *
     * @param  Closure(SimulationContext): T  $callback
     * @return T
     */
    public function run(Fixture $fixture, Closure $callback, ?CarbonImmutable $startingAt = null): mixed
    {
        if ($this->running) {
            throw SimulationFailed::alreadyRunning();
        }

        $this->running = true;

        try {
            // The transactions are outermost: opened before anything else moves,
            // so the billable factory's writes are isolated, and rolled back
            // only once every piece of global state has been put back.
            return $this->isolated(fn (): mixed => $this->sealed($fixture, $callback, $startingAt));
        } finally {
            $this->running = false;
        }
    }

    /**
     * Run the callback inside a transaction on each isolated connection, and
     * always roll each back to the level it was entered at.
     *
     * Isolation failures are recorded during cleanup and raised only after it
     * has finished, so an exception already on its way out is not replaced.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function isolated(Closure $callback): mixed
    {
        // Resolved before the billable factory runs: its INSERT must be
        // isolated too.
        $connections = $this->isolatedConnections();

        /** @var array<string, int> $entryLevels */
        $entryLevels = [];

        $exceptionInFlight = false;

        try {
            foreach ($connections as $name => $database) {
                $entryLevels[$name] = $database->transactionLevel();

                // Inside the try, so a listener on Laravel's transaction-begin
                // event that throws after PDO has already begun still reaches
                // the rollback below instead of leaking an open transaction.
                $database->beginTransaction();
            }

            $result = $callback();

            $pendingCallbacks = 0;

            foreach ($connections as $name => $database) {
                $pendingCallbacks += $this->afterCommitGuard->callbacksDiscardedByRollback($database, $entryLevels[$name]);
            }
        } catch (Throwable $e) {
            $exceptionInFlight = true;

            throw $e;
        } finally {
            $ownershipLost = false;
            $rollbackFailure = null;

            foreach ($entryLevels as $name => $entryLevel) {
                $database = $connections[$name];

                // Did application code end the transaction we opened?
                //
                // `Connection::rollBack($toLevel)` returns without doing
                // anything when `$toLevel` is not below the connection's own
                // transaction count, so a webhook handler that called
                // DB::commit() turns the rollback below into a silent no-op and
                // the replay's writes become permanent — with the run still
                // reporting PASS. Recorded here and raised after the finally,
                // so an exception already on its way out is not swallowed by
                // this one.
                $ownershipLost = $ownershipLost || $database->transactionLevel() <= $entryLevel;

                // A replay is a question, not a migration. Restore our entry
                // level, including any nested transaction application code left
                // open, without rolling back a caller-owned transaction.
                //
                // Each connection is restored even if another one's rollback
                // throws, so one failure cannot leave a second transaction open.
                try {
                    $database->rollBack($entryLevel);
                } catch (Throwable $e) {
                    $rollbackFailure ??= $e;
                }
            }

            // Throwing from `finally` would replace an exception already on its
            // way out, and that exception is the one that explains why the run
            // ended. The run still fails either way.
            if ($rollbackFailure !== null && ! $exceptionInFlight) {
                throw $rollbackFailure;
            }
        }

        if ($ownershipLost) {
            throw SimulationFailed::replayTransactionWasEnded();
        }

        if ($pendingCallbacks > 0) {
            throw SimulationFailed::afterCommitCallbacksCannotBeObserved($pendingCallbacks);
        }

        return $result;
    }

    /**
     * The connections a replay rolls back, keyed by name: the billable's and
     * the application's default, once when they are the same (ADR-0001).
     *
     * Cashier uses the billable model to find customers, and it may declare a
     * non-default connection; the application's own writes usually land on the
     * default one. Writes to any other connection are outside the guarantee —
     * isolating every configured connection would require each configured
     * database to be reachable just to run a replay.
     *
     * @return array<string, Connection>
     */
    private function isolatedConnections(): array
    {
        $connections = [];

        foreach ([$this->billableConnection(), $this->databaseManager->connection()] as $connection) {
            $connections[$connection->getName()] ??= $connection;
        }

        return $connections;
    }

    /**
     * Swap the global state a replay needs, build the billable, run the
     * callback, and put every piece back whatever happens.
     *
     * @template T
     *
     * @param  Closure(SimulationContext): T  $callback
     * @return T
     */
    private function sealed(Fixture $fixture, Closure $callback, ?CarbonImmutable $startingAt): mixed
    {
        $credentials = EphemeralCredentials::generate();
        $startedAt = $startingAt ?? CarbonImmutable::now();
        $placeholders = new Placeholders($startedAt);
        $client = new FixtureHttpClient($fixture, $placeholders);
        $expectedBillableStripeId = $this->expectedBillableStripeId($fixture, $placeholders);

        // Captured before anything is swapped. Reading the transport instantiates
        // Stripe's default client if none was set, which is what we want to put
        // back: the alternative is restoring null and changing behaviour for
        // whatever runs next.
        $originalTransport = ApiRequestor::httpClient();
        $originalConfig = [
            'cashier.secret' => $this->config->get('cashier.secret'),
            'cashier.webhook.secret' => $this->config->get('cashier.webhook.secret'),
        ];
        $originalTestNow = Carbon::getTestNow();
        $originalMailers = $this->config->get('mail.mailers');
        $ledger = new SideEffectLedger;

        try {
            // Armed first, so a billable factory that sends mail or queues a
            // job is observed and refused like everything after it.
            $this->outboundGuard->arm($ledger);
            $this->config->set('cashier.secret', $credentials->secret);
            $this->config->set('cashier.webhook.secret', $credentials->webhookSecret);
            $this->silenceMailTransports();

            ApiRequestor::setHttpClient($client);

            // The billable is built INSIDE the swap, not before it.
            //
            // A factory is an ordinary piece of application code and
            // `$user->createAsStripeCustomer()` is Cashier's documented way to
            // make one. Constructed before the credentials moved, that call
            // reached the developer's real Stripe account with their real key —
            // the exact thing this class exists to make impossible.
            $context = new SimulationContext(
                billable: $this->billable($expectedBillableStripeId),
                credentials: $credentials,
                startedAt: $startedAt,
                fixture: $fixture,
                client: $client,
                placeholders: $placeholders,
                ledger: $ledger,
            );

            return $callback($context);
        } finally {
            // First, so a restoration step that throws below cannot leave the
            // guard refusing the application's mail outside a run.
            $this->outboundGuard->disarm();

            // Unconditional, including on the way out of an exception. A leaked
            // transport or a frozen clock would fail some later test instead,
            // and that failure would be unreadable.
            ApiRequestor::setHttpClient($originalTransport);

            foreach ($originalConfig as $key => $value) {
                $this->config->set($key, $value);
            }

            $this->config->set('mail.mailers', $originalMailers);
            $this->purgeMailers();

            Carbon::setTestNow($originalTestNow);
            CarbonImmutable::setTestNow($originalTestNow);
        }
    }

    /**
     * Point every configured mailer at the array transport for the run.
     *
     * Blocking delivery by returning `false` from `MessageSending` is not
     * enough on its own. `Dispatcher::until()` stops at the first listener that
     * answers, so an application listener registered earlier and returning any
     * non-null value means the guard is never consulted and the mail goes out.
     * A transport that cannot reach the network does not care about listener
     * ordering.
     *
     * Every mailer is swapped, not just the default one: an application is free
     * to send its dunning mail through `Mail::mailer('postmark')`, and a swap
     * that only covered `mail.default` would let exactly that case through.
     *
     * The message is still built, the recipients are still resolved, and
     * `MessageSending` still fires for the ledger — the run stays real right up
     * to the transport.
     */
    private function silenceMailTransports(): void
    {
        $mailers = $this->config->get('mail.mailers');

        if (! is_array($mailers)) {
            return;
        }

        foreach (array_keys($mailers) as $name) {
            $this->config->set("mail.mailers.{$name}", ['transport' => 'array']);
        }

        $this->purgeMailers();
    }

    /**
     * Drop resolved mailer instances so the swapped config is what gets used.
     *
     * `MailManager` memoises each mailer, so changing configuration after one
     * has been resolved has no effect until it is forgotten.
     */
    private function purgeMailers(): void
    {
        $container = Container::getInstance();

        if (! $container->resolved('mail.manager')) {
            return;
        }

        $container->make('mail.manager')->forgetMailers();
    }

    private function billableConnection(): Connection
    {
        /** @var class-string<Model> $model */
        $model = Cashier::$customerModel;

        return (new $model)->getConnection();
    }

    /**
     * Every customer row carrying this stripe_id.
     *
     * Mirrors `Cashier::findBillable()` — same model, same soft-delete
     * handling — but returns the whole set rather than the first hit. That is
     * the difference between "Cashier will find something" and "Cashier will
     * find the right thing": with two rows sharing an id, `first()` succeeds
     * and silently picks one, which is exactly the v0.1.0 residue case this
     * preflight exists to name.
     *
     * @return Collection<int, Model>
     */
    private function customersWithStripeId(string $stripeId): Collection
    {
        /** @var class-string<Model> $model */
        $model = Cashier::$customerModel;

        $builder = $model::query();

        // `withTrashed()` is a macro that only exists once SoftDeletingScope has
        // registered it, so it cannot be called on a plain class-string. Its
        // whole body is this line (SoftDeletingScope::addWithTrashed), and
        // calling the scope directly says the same thing to a type checker.
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $builder->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $builder->where('stripe_id', $stripeId)->get();
    }

    /**
     * The model the billing lifecycle belongs to.
     *
     * A registered factory wins. Failing that, an Eloquent factory on the
     * configured Cashier model is tried, because that is what almost every
     * application already has and asking them to write a closure that says the
     * same thing is friction for nothing.
     */
    private function billable(?string $expectedBillableStripeId): object
    {
        if ($factory = CashierDunning::billableFactory()) {
            $billable = $factory();

            if (! is_object($billable)) {
                throw SimulationFailed::billableFactoryReturnedNonObject(get_debug_type($billable));
            }

            return $this->validatedBillable($billable, $expectedBillableStripeId);
        }

        // Cashier keeps the billable model in a static, not in configuration.
        $model = Cashier::$customerModel;

        if (method_exists($model, 'factory')) {
            try {
                $billable = $model::factory()->create();
            } catch (Throwable $e) {
                throw new SimulationFailed(
                    "Could not build a billable from [{$model}] using its factory: ".
                    $e->getMessage()."\n\n".
                    'Register one explicitly with CashierDunning::createBillableUsing().',
                    previous: $e
                );
            }

            return $this->validatedBillable($billable, $expectedBillableStripeId);
        }

        throw SimulationFailed::noBillable();
    }

    private function expectedBillableStripeId(Fixture $fixture, Placeholders $placeholders): ?string
    {
        $placeholder = $fixture->customerPlaceholder();

        return $placeholder === null
            ? null
            : (string) $placeholders->resolveString($placeholder);
    }

    private function validatedBillable(object $billable, ?string $expectedBillableStripeId): object
    {
        if ($expectedBillableStripeId === null) {
            return $billable;
        }

        $stripeId = method_exists($billable, 'stripeId')
            ? $billable->stripeId()
            : null;

        if (! is_string($stripeId) || $stripeId !== $expectedBillableStripeId) {
            throw SimulationFailed::billableStripeIdDoesNotMatch(
                $billable::class,
                $expectedBillableStripeId,
                is_string($stripeId) ? $stripeId : null,
            );
        }

        // Three ways this can go wrong, and they need three different
        // sentences. Collapsing them into "returned a different record" sends
        // someone hunting for duplicate rows when the real problem is a
        // misconfigured customer model or a billable Cashier cannot query.
        if (! $billable instanceof Model) {
            throw SimulationFailed::billableIsNotEloquent($billable::class);
        }

        $matches = $this->customersWithStripeId($expectedBillableStripeId);

        if ($matches->isEmpty()) {
            throw SimulationFailed::billableCouldNotBeResolved(
                $billable::class,
                $expectedBillableStripeId,
            );
        }

        if ($matches->count() > 1) {
            throw SimulationFailed::billableIdIsNotUnique(
                $matches->count(),
                $expectedBillableStripeId,
            );
        }

        // Model::is() compares key, table and connection, which is exactly the
        // question here: did the lookup land on the row the factory wrote?
        if (! $billable->is($matches->first())) {
            throw SimulationFailed::billableResolvedToDifferentRecord(
                $billable::class,
                $expectedBillableStripeId,
            );
        }

        return $billable;
    }
}
