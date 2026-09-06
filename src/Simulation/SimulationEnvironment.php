<?php

namespace Impruthvi\CashierDunning\Simulation;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Impruthvi\CashierDunning\CashierDunning;
use Impruthvi\CashierDunning\Simulation\Exceptions\SimulationFailed;
use Laravel\Cashier\Cashier;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Throwable;

/**
 * Sets up the world a replay runs in, and — the part that matters — puts it
 * back.
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
 * All three are process-global. Leaking any of them out of a simulation would
 * corrupt every test that runs afterwards — and the corruption would surface
 * somewhere else entirely, as a test that fails only when run in a certain
 * order. So restoration happens in `finally`, unconditionally, including when
 * the callback throws.
 */
final class SimulationEnvironment
{
    private static bool $running = false;

    public function __construct(
        private readonly Repository $config,
        private readonly ?ClientInterface $httpClient = null,
        private readonly ?string $expectedBillableStripeId = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(SimulationContext): T  $callback
     * @return T
     */
    public function run(Closure $callback, ?CarbonImmutable $startingAt = null): mixed
    {
        if (self::$running) {
            throw SimulationFailed::alreadyRunning();
        }

        $credentials = EphemeralCredentials::generate();

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

        self::$running = true;

        try {
            $this->config->set('cashier.secret', $credentials->secret);
            $this->config->set('cashier.webhook.secret', $credentials->webhookSecret);
            $this->silenceMailTransports();

            if ($this->httpClient !== null) {
                ApiRequestor::setHttpClient($this->httpClient);
            }

            // The billable is built INSIDE the swap, not before it.
            //
            // A factory is an ordinary piece of application code and
            // `$user->createAsStripeCustomer()` is Cashier's documented way to
            // make one. Constructed before the credentials moved, that call
            // reached the developer's real Stripe account with their real key —
            // the exact thing this class exists to make impossible.
            $context = new SimulationContext(
                billable: $this->billable(),
                credentials: $credentials,
                startedAt: $startingAt ?? CarbonImmutable::now(),
            );

            $context->advanceTo($context->position());

            return $callback($context);
        } finally {
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

            self::$running = false;
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
    private function billable(): object
    {
        if ($factory = CashierDunning::billableFactory()) {
            $billable = $factory();

            if (! is_object($billable)) {
                throw SimulationFailed::billableFactoryReturnedNonObject(get_debug_type($billable));
            }

            return $this->validatedBillable($billable);
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

            return $this->validatedBillable($billable);
        }

        throw SimulationFailed::noBillable();
    }

    private function validatedBillable(object $billable): object
    {
        if ($this->expectedBillableStripeId === null) {
            return $billable;
        }

        $stripeId = method_exists($billable, 'stripeId')
            ? $billable->stripeId()
            : null;

        if (! is_string($stripeId) || $stripeId !== $this->expectedBillableStripeId) {
            throw SimulationFailed::billableStripeIdDoesNotMatch(
                $billable::class,
                $this->expectedBillableStripeId,
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

        $matches = $this->customersWithStripeId($this->expectedBillableStripeId);

        if ($matches->isEmpty()) {
            throw SimulationFailed::billableCouldNotBeResolved(
                $billable::class,
                $this->expectedBillableStripeId,
            );
        }

        if ($matches->count() > 1) {
            throw SimulationFailed::billableIdIsNotUnique(
                $matches->count(),
                $this->expectedBillableStripeId,
            );
        }

        // Model::is() compares key, table and connection, which is exactly the
        // question here: did the lookup land on the row the factory wrote?
        if (! $billable->is($matches->first())) {
            throw SimulationFailed::billableResolvedToDifferentRecord(
                $billable::class,
                $this->expectedBillableStripeId,
            );
        }

        return $billable;
    }
}
