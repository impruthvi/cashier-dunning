<?php

namespace Impruthvi\CashierDunning\Simulation;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Config\Repository;
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
        $context = new SimulationContext(
            billable: $this->billable(),
            credentials: $credentials,
            startedAt: $startingAt ?? CarbonImmutable::now(),
        );

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

        self::$running = true;

        try {
            $this->config->set('cashier.secret', $credentials->secret);
            $this->config->set('cashier.webhook.secret', $credentials->webhookSecret);

            if ($this->httpClient !== null) {
                ApiRequestor::setHttpClient($this->httpClient);
            }

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

            Carbon::setTestNow($originalTestNow);
            CarbonImmutable::setTestNow($originalTestNow);

            self::$running = false;
        }
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

            return $billable;
        }

        // Cashier keeps the billable model in a static, not in configuration.
        $model = Cashier::$customerModel;

        if (method_exists($model, 'factory')) {
            try {
                return $model::factory()->create();
            } catch (Throwable $e) {
                throw new SimulationFailed(
                    "Could not build a billable from [{$model}] using its factory: ".
                    $e->getMessage()."\n\n".
                    'Register one explicitly with CashierDunning::createBillableUsing().',
                    previous: $e
                );
            }
        }

        throw SimulationFailed::noBillable();
    }
}
